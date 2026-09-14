<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Exceptions\PostingDateOutsidePeriodException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * A payroll entry used to be dated the last day of the month whatever the bank
 * statement said. It can now name the day it was paid — bounded to the payroll
 * month, because the ledger refuses a closed fiscal year but not a VAT period
 * that has already been settled.
 */
class PostingDateTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        foreach ([
            ['1020', AccountType::Asset], ['2270', AccountType::Liability],
            ['2271', AccountType::Liability], ['2272', AccountType::Liability],
            ['5000', AccountType::Expense], ['5700', AccountType::Expense],
            ['6530', AccountType::Expense],
        ] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->organization->id,
                'code' => $code,
                'name' => 'Konto '.$code,
                'type' => $type->value,
            ]);
        }

        $this->employee = Employee::factory()->create([
            'organization_id' => $this->organization->id,
            'gross_salary' => '5000.00',
        ]);
    }

    private function slip(?string $postingDate = null, int $month = 9): SalarySlip
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee, $month, 2026);
        $slip->posting_date = $postingDate;
        $slip->save();

        return $slip;
    }

    // ──────────────────────────────────────────────────────────────

    public function test_without_a_date_the_entry_still_falls_on_the_last_day_of_the_month(): void
    {
        $slip = app(PostPayrollAction::class)->execute($this->slip());

        $this->assertSame('2026-09-30', $slip->journalEntry->date->toDateString());
    }

    public function test_the_organizations_payday_is_used_when_the_slip_names_no_date(): void
    {
        $this->organization->update(['payroll_payday' => 26]);

        $slip = app(PostPayrollAction::class)->execute($this->slip());

        $this->assertSame('2026-09-26', $slip->journalEntry->date->toDateString());
    }

    public function test_a_payday_past_the_end_of_a_short_month_lands_on_its_last_day(): void
    {
        $this->organization->update(['payroll_payday' => 31]);

        $slip = app(PostPayrollAction::class)->execute($this->slip(month: 2));

        $this->assertSame('2026-02-28', $slip->journalEntry->date->toDateString());
    }

    public function test_the_slips_own_date_wins(): void
    {
        $this->organization->update(['payroll_payday' => 26]);

        $slip = app(PostPayrollAction::class)->execute($this->slip('2026-09-15'));

        $this->assertSame('2026-09-15', $slip->journalEntry->date->toDateString());
    }

    public function test_a_date_outside_the_payroll_month_is_refused_at_posting(): void
    {
        $slip = $this->slip('2026-06-26');

        $this->expectException(PostingDateOutsidePeriodException::class);

        app(PostPayrollAction::class)->execute($slip);
    }

    public function test_a_run_rejects_a_date_outside_the_payroll_month(): void
    {
        $response = $this->actAsOrg()->postJson('/payroll/run', [
            'month' => 9,
            'year' => 2026,
            'employee_ids' => [$this->employee->id],
            'posting_date' => '2026-10-05',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('posting_date');
        $this->assertSame(0, SalarySlip::count());
    }

    public function test_a_run_stores_the_date_it_was_given(): void
    {
        $this->actAsOrg()->postJson('/payroll/run', [
            'month' => 9,
            'year' => 2026,
            'employee_ids' => [$this->employee->id],
            'posting_date' => '2026-09-26',
        ])->assertOk();

        $this->assertSame('2026-09-26', SalarySlip::sole()->posting_date->toDateString());
    }
}
