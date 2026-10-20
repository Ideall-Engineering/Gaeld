<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Services\VatReportService;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Expenses\Enums\ExpenseStatus;
use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Models\RecurringExpense;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Projects\Enums\ProjectStatus;
use App\Domains\Projects\Models\BudgetGroup;
use App\Domains\Projects\Models\PlannedExpense;
use App\Domains\Projects\Services\AcquisitionService;
use App\Domains\Projects\Services\ExpectedExpensesService;
use App\Domains\Projects\Services\SteeringService;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The expenses still expected on the steering page (spec 012): salaries
 * with the employer's contributions, recurring expenses, open expenses,
 * acquisitions, single items, cost block rests and VAT, the outlook month by
 * month, and who may see and change what.
 */
class ExpectedExpensesTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $anna;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->setUpOrganization();
        $this->enableProjects();

        $this->anna = Employee::factory()->for($this->organization)->create([
            'first_name' => 'Anna', 'last_name' => 'A', 'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00', 'has_thirteenth_salary' => true, 'expense_allowance' => '200.00',
        ]);
        foreach ([['avs_employee', '5.3000', 'employee'], ['avs_employer', '5.3000', 'employer'], ['lpp_employer', '7.0000', 'employer']] as [$code, $rate, $type]) {
            DeductionRate::create([
                'organization_id' => $this->organization->id, 'name' => $code, 'code' => $code,
                'rate' => $rate, 'type' => $type, 'is_active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_salaries_count_from_this_month_without_a_booked_slip(): void
    {
        // October is booked already: it drops out.
        $this->slip(10, bookedExternally: true);
        // A draft slip counts for nothing: November stays expected.
        $this->slip(11, bookedExternally: false);

        $data = $this->expected(2026);
        $salaries = collect($data['rows'])->where('source', 'salary')->values();
        $social = collect($data['rows'])->where('source', 'social')->values();

        $this->assertSame(['2026-11-25', '2026-12-25'], $salaries->pluck('date')->all());
        $this->assertSame('6200.00', $salaries[0]['amount']);
        // December with the 13th salary, the flat allowance once.
        $this->assertSame('12200.00', $salaries[1]['amount']);
        $this->assertSame('Anna A', $salaries[0]['label']);
        // Employer: 12.3 % of the gross; the employee's own part is not a cost.
        $this->assertSame(['738.00', '1476.00'], $social->pluck('amount')->all());
        $this->assertSame('18400.00', $data['by_source']['salary']);
        $this->assertSame('2214.00', $data['by_source']['social']);
    }

    public function test_next_year_counts_every_month_and_a_past_year_none(): void
    {
        $next = $this->expected(2027);
        $this->assertCount(12, collect($next['rows'])->where('source', 'salary'));
        $this->assertSame('2027-01-25', $next['rows'][0]['date']);

        $past = $this->expected(2025);
        $this->assertSame([], $past['rows']);
    }

    public function test_salaries_stop_after_the_exit_and_hourly_wages_are_named(): void
    {
        $this->anna->update(['exit_date' => '2026-11-30']);
        Employee::factory()->for($this->organization)->create([
            'first_name' => 'Ben', 'last_name' => 'B', 'salary_type' => 'hourly', 'hourly_rate' => '40.00', 'gross_salary' => '0.00',
        ]);

        $data = $this->expected(2026);

        $this->assertSame(['2026-10-25', '2026-11-25'], collect($data['rows'])->where('source', 'salary')->pluck('date')->values()->all());
        $this->assertSame(['Ben B'], $data['hourly']);
    }

    public function test_recurring_expenses_count_each_due_day_up_to_their_end(): void
    {
        $this->anna->update(['is_active' => false]);
        $this->recurring(['description' => 'Rent', 'amount' => '1500.00', 'vat_amount' => '0.00', 'next_due_date' => '2026-11-01']);
        $this->recurring(['description' => 'Hosting', 'amount' => '100.00', 'vat_amount' => '8.10', 'next_due_date' => '2026-11-01', 'end_date' => '2026-11-15']);
        $this->recurring(['description' => 'Paused', 'amount' => '999.00', 'next_due_date' => '2026-11-01', 'is_active' => false]);

        $data = $this->expected(2026);
        $rows = collect($data['rows'])->where('source', 'recurring')->values();

        $this->assertSame([['2026-11-01', 'Hosting', '100.00'], ['2026-11-01', 'Rent', '1500.00'], ['2026-12-01', 'Rent', '1500.00']],
            $rows->map(fn (array $r) => [$r['date'], $r['label'], $r['amount']])->all());
        $this->assertSame('6000', $rows[0]['account']);
        $this->assertSame('3100.00', $data['total']);
    }

    public function test_open_expenses_count_until_posted_and_past_ones_are_overdue(): void
    {
        $this->anna->update(['is_active' => false]);
        $expense = fn (string $date, ExpenseStatus $status, string $amount) => Expense::factory()->for($this->organization)->create([
            'date' => $date, 'status' => $status->value, 'amount' => $amount, 'vat_amount' => '10.00', 'vendor' => "Vendor {$date}", 'supplier_id' => null,
        ]);
        $expense('2026-09-01', ExpenseStatus::Pending, '300.00');
        $expense('2026-11-10', ExpenseStatus::Approved, '200.00');
        $expense('2026-11-11', ExpenseStatus::Posted, '999.00');
        $expense('2026-11-12', ExpenseStatus::Cancelled, '999.00');
        $expense('2027-02-01', ExpenseStatus::Pending, '50.00');

        $rows = collect($this->expected(2026)['rows'])->where('source', 'expense')->values();
        $this->assertSame([['2026-09-01', '300.00', true], ['2026-11-10', '200.00', false]],
            $rows->map(fn (array $r) => [$r['date'], $r['amount'], $r['overdue']])->all());

        $next = collect($this->expected(2027)['rows'])->where('source', 'expense')->values();
        $this->assertSame(['2027-02-01'], $next->pluck('date')->all());
    }

    public function test_the_page_carries_both_years(): void
    {
        $this->actAsOrg()->get('/projects/steering?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canSteer', true)
                ->where('steering.expected_expenses.by_source.salary', '24600.00')
                ->has('steering.expected_expenses_next_year.rows', 24));
    }

    public function test_accountants_see_the_steering_but_change_nothing(): void
    {
        $accountant = $this->member('accountant');
        $as = fn () => $this->actingAs($accountant)->withSession(['current_organization_id' => $this->organization->id]);

        $as()->get('/projects/steering?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canSteer', false)->has('steering.expected_expenses.rows'));
        $as()->get('/projects/steering/export/csv?year=2026')->assertOk();
        $as()->post('/projects/steering/items', [
            'year' => 2026, 'employee_id' => $this->anna->id, 'label' => 'x', 'amount' => '1.00',
        ])->assertForbidden();

        foreach (['member', 'viewer'] as $role) {
            $this->actingAs($this->member($role))->withSession(['current_organization_id' => $this->organization->id])
                ->get('/projects/steering?year=2026')
                ->assertForbidden();
        }
    }

    public function test_salary_rows_carry_the_accounts_the_posting_uses(): void
    {
        DeductionRate::where('code', 'lpp_employer')->update(['expense_account_code' => '5720']);

        $rows = collect($this->expected(2026)['rows'])->where('date', '2026-10-25');

        $this->assertSame('5000', $rows->firstWhere('source', 'salary')['account']);
        $this->assertSame(['5700' => '318.00', '5720' => '420.00'], $rows->where('source', 'social')->pluck('amount', 'account')->all());
    }

    public function test_ordered_acquisitions_count_until_booked(): void
    {
        $this->anna->update(['is_active' => false]);
        $save = fn (string $item, string $status) => app(AcquisitionService::class)->save(null, $this->organization->id, [
            'item' => $item, 'supplier' => 'Shop', 'purchased_on' => '2026-11-03', 'quantity' => '1', 'unit_price' => '1081.00', 'vat_rate' => '8.1', 'status' => $status,
        ], []);
        $save('Laptop', 'ordered');
        $save('Mouse', 'delivered');

        $rows = collect($this->expected(2026)['rows'])->where('source', 'acquisition')->values();

        $this->assertSame([['Laptop · Shop', '1000.00', '1081.00']], $rows->map(fn (array $r) => [$r['label'], $r['amount'], $r['gross']])->all());
    }

    public function test_single_items_are_entered_marked_done_and_removed(): void
    {
        $this->anna->update(['is_active' => false]);
        $accountant = $this->member('accountant');
        $as = fn () => $this->actingAs($accountant)->withSession(['current_organization_id' => $this->organization->id]);

        $as()->post('/projects/steering/planned-expenses', ['date' => '2026-12-10', 'label' => 'Tax bill', 'amount' => '2500.00'])
            ->assertSessionHasNoErrors();
        $planned = PlannedExpense::firstOrFail();
        $row = collect($this->expected(2026)['rows'])->firstWhere('source', 'planned');
        $this->assertSame(['Tax bill', '2500.00', $planned->id], [$row['label'], $row['amount'], $row['id']]);

        $as()->post('/projects/steering/planned-expenses', ['date' => '2026-12-10', 'label' => 'x', 'amount' => '1', 'account_code' => '9999'])
            ->assertSessionHasErrors('account_code');

        $as()->put("/projects/steering/planned-expenses/{$planned->id}", ['done' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($planned->fresh()->done_at);
        $this->assertNull(collect($this->expected(2026)['rows'])->firstWhere('source', 'planned'));

        $as()->delete("/projects/steering/planned-expenses/{$planned->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, PlannedExpense::count());

        $this->actingAs($this->member('member'))->withSession(['current_organization_id' => $this->organization->id])
            ->post('/projects/steering/planned-expenses', ['date' => '2026-12-10', 'label' => 'x', 'amount' => '1'])
            ->assertForbidden();

        // Another organization's item is out of reach.
        $foreign = PlannedExpense::withoutGlobalScopes()->create(['organization_id' => Organization::factory()->create()->id, 'date' => '2026-12-10', 'label' => 'Foreign', 'amount' => '1.00']);
        $status = $as()->put("/projects/steering/planned-expenses/{$foreign->id}", ['done' => true])->getStatusCode();
        $this->assertContains($status, [403, 404]);
        $this->assertNull($foreign->fresh()->done_at);
    }

    public function test_a_cost_block_adds_only_what_the_other_rows_leave_of_its_plan(): void
    {
        $this->anna->update(['is_active' => false]);
        $this->setUpPostedEntryFixtures();
        $this->postSimpleEntry('2026-03-01', 'RENT-3', '1000.00');
        $this->recurring(['description' => 'Rent', 'amount' => '500.00', 'next_due_date' => '2026-11-01']);
        $block = BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Premises', 'kind' => 'running', 'plan_amount' => '5000.00']);
        $block->accounts()->sync([$this->expense->id]);
        BudgetGroup::create(['organization_id' => $this->organization->id, 'year' => 2026, 'name' => 'Spent', 'kind' => 'running', 'plan_amount' => '100.00'])
            ->accounts()->sync([$this->otherExpense->id]);
        $this->postSimpleEntry('2026-04-01', 'OFFICE', '300.00', $this->otherExpense);

        $data = $this->expected(2026);
        $blocks = collect($data['rows'])->where('source', 'block')->values();

        // 5000 planned, 1000 booked, 2 x 500 recurring: 3000 left, undated, last.
        $this->assertSame([['Premises', '3000.00', null]], $blocks->map(fn (array $r) => [$r['label'], $r['amount'], $r['date']])->all());
        $this->assertSame('block', $data['rows'][array_key_last($data['rows'])]['source']);
        $this->assertSame('4000.00', $data['total']);
    }

    public function test_vat_is_listed_by_due_date_but_kept_out_of_the_net_total(): void
    {
        $this->anna->update(['is_active' => false]);
        $this->setUpPostedEntryFixtures();
        $settlement = $this->makeAccount('2201', 'VAT settlement', AccountType::Liability);
        $this->postSimpleEntry('2026-07-31', 'VAT-Q2', '400.00', $this->expense, $settlement);
        $this->lockVatQuarter('2026-04-01', '2026-06-30');
        $this->lockVatQuarter('2026-01-01', '2026-03-31');
        $this->lockVatQuarter('2025-10-01', '2025-12-31');
        $this->mock(VatReportService::class, fn ($mock) => $mock->shouldReceive('generate')->andReturnUsing(fn (string $org, string $from) => [
            'vat_payable' => ['2026-07-01' => '1200.00', '2026-10-01' => '300.00'][$from] ?? '0.00',
            'vat_credit' => '0.00',
        ]));

        $data = $this->expected(2026);
        $vat = collect($data['rows'])->where('source', 'vat')->values();

        $this->assertSame([['2026-10-20', 'settled', '400.00'], ['2026-11-29', 'open', '1200.00']],
            $vat->map(fn (array $r) => [$r['date'], $r['label'], $r['amount']])->all());
        $this->assertFalse($vat[0]['in_result']);
        $this->assertSame('0.00', $data['total']);
        $this->assertSame('1600.00', $data['by_source']['vat']);

        // The running quarter is paid next year.
        $next = collect($this->expected(2027)['rows'])->where('source', 'vat')->values();
        $this->assertSame([['2027-03-01', 'running', '300.00', 'Q4 2026']], $next->map(fn (array $r) => [$r['date'], $r['label'], $r['amount'], $r['quarter']])->all());
    }

    public function test_the_outlook_runs_month_by_month_from_todays_bank_balance(): void
    {
        $this->anna->update(['is_active' => false]);
        $this->setUpPostedEntryFixtures();
        BankAccount::create(['uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'account_id' => $this->bank->id, 'name' => 'Main', 'currency' => 'CHF', 'is_active' => true]);
        $this->postSimpleEntry('2026-01-05', 'CAPITAL', '10000.00', $this->bank, $this->revenue);
        PlannedExpense::create(['organization_id' => $this->organization->id, 'date' => '2026-11-15', 'label' => 'Premium', 'amount' => '1000.00', 'vat_amount' => '81.00']);
        $this->makeProject('JOB')->update(['status' => ProjectStatus::InProgress, 'expected_amount' => '3000.00', 'billing_month' => '2026-12-01']);

        $outlook = app(SteeringService::class)->forYear($this->organization->id, 2026)['outlook'];
        $months = collect($outlook['months'])->keyBy('key');

        $this->assertSame('10000.00', $outlook['bank_today']);
        $this->assertCount(15, $outlook['months']);
        $this->assertSame(['1000.00', '1081.00', '8919.00', '-1000.00'], [$months['2026-11']['expenses'], $months['2026-11']['cash_out'], $months['2026-11']['bank'], $months['2026-11']['result']]);
        $this->assertSame(['3000.00', '11919.00', '2000.00'], [$months['2026-12']['income'], $months['2026-12']['bank'], $months['2026-12']['result']]);
        $this->assertNull(app(SteeringService::class)->forYear($this->organization->id, 2025)['outlook']);
    }

    public function test_the_export_carries_the_expected_expenses(): void
    {
        $csv = $this->actAsOrg()->get('/projects/steering/export/csv?year=2026')->streamedContent();

        $this->assertStringContainsString('Anna A', $csv);
        $this->assertStringContainsString('12200.00', $csv);
        $this->actAsOrg()->get('/projects/steering/export/pdf?year=2026')->assertOk();
    }

    /** @return array<string, mixed> */
    private function expected(int $year): array
    {
        return app(ExpectedExpensesService::class)->forYear($this->organization->id, $year);
    }

    private function slip(int $month, bool $bookedExternally): void
    {
        SalarySlip::create([
            'organization_id' => $this->organization->id,
            'employee_id' => $this->anna->id,
            'period_month' => $month,
            'period_year' => 2026,
            'gross_salary' => '6000.00',
            'net_salary' => '5682.00',
            'booked_externally' => $bookedExternally,
            'deductions' => [],
            'adjustments' => [],
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function recurring(array $attributes): void
    {
        RecurringExpense::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'category' => 'office',
            'expense_account_code' => '6000',
            'frequency' => 'monthly',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($user->id, ['role' => $role]);
        $this->assignOrganizationRole($user, $this->organization, $role);

        return $user;
    }
}
