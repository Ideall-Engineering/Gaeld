<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Payroll\Actions\GeneratePayrollRunAction;
use App\Domains\Payroll\Actions\GenerateSalaryCertificateAction;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * The salary certificate follows the layout of the official Swiss Form 11,
 * with the numbered boxes the tax authority reads.
 *
 * The document is Form-11-shaped; it is not a certified Swissdec submission,
 * which is a separate procedure this module does not implement.
 */
class SalaryCertificateForm11Test extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setUpOrganization();

        foreach ([
            ['code' => '1020', 'name' => 'Bank', 'type' => AccountType::Asset->value],
            ['code' => '5000', 'name' => 'Salaries', 'type' => AccountType::Expense->value],
            ['code' => '5700', 'name' => 'Social Charges', 'type' => AccountType::Expense->value],
            ['code' => '6530', 'name' => 'General Expense', 'type' => AccountType::Expense->value],
            ['code' => '2270', 'name' => 'AVS Payable', 'type' => AccountType::Liability->value],
            ['code' => '2271', 'name' => 'AC Payable', 'type' => AccountType::Liability->value],
            ['code' => '2272', 'name' => 'LPP Payable', 'type' => AccountType::Liability->value],
            ['code' => '2273', 'name' => 'Withholding Tax Payable', 'type' => AccountType::Liability->value],
        ] as $account) {
            Account::create(array_merge($account, ['organization_id' => $this->org->id]));
        }

        $this->employee = Employee::factory()
            ->withCertificateDetails()
            ->create([
                'organization_id' => $this->org->id,
                'entry_date' => '2025-01-01',
                'gross_salary' => '6000.00',
                'is_active' => true,
            ]);
    }

    #[Test]
    public function it_reports_the_year_under_the_official_box_numbers(): void
    {
        $this->postSlip(3);

        $chiffres = collect(
            app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026)['chiffres']
        )->keyBy('chiffre');

        // One month at 6000.00 gross; the deduction rates are the Swiss
        // defaults seeded for every organization.
        $this->assertSame('6000.00', $chiffres['1']['amount'], 'Ziff. 1 salary');
        $this->assertSame('6000.00', $chiffres['8']['amount'], 'Ziff. 8 total gross');
        $this->assertSame('444.00', $chiffres['9']['amount'], 'Ziff. 9 AHV/IV/EO/ALV/NBUV');
        $this->assertSame('420.00', $chiffres['10.1']['amount'], 'Ziff. 10.1 pension');
        $this->assertSame('5136.00', $chiffres['11']['amount'], 'Ziff. 11 net salary');
        $this->assertSame('0.00', $chiffres['12']['amount'], 'Ziff. 12 withholding tax');
    }

    #[Test]
    public function a_contribution_that_may_not_reduce_the_gross_stays_out_of_the_boxes(): void
    {
        // A sickness daily-allowance contribution charged to the employee is
        // not deductible and must not reduce the gross salary, so box 9 cannot
        // hold it and box 11 — box 8 less boxes 9 and 10 — must not feel it.
        $this->seedRatesWithSicknessAllowance();
        $this->postSlip(3);

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026);
        $chiffres = collect($certificate['chiffres'])->keyBy('chiffre');

        $this->assertSame('444.00', $chiffres['9']['amount'], 'Ziff. 9 carries AHV/ALV/NBU only');
        $this->assertSame('420.00', $chiffres['10.1']['amount']);
        $this->assertSame('5136.00', $chiffres['11']['amount'], 'Ziff. 11 = 8 − 9 − 10.1');

        // What the employee actually received is lower, and says so elsewhere.
        $this->assertSame('5089.29', $certificate['total_paid']);
    }

    #[Test]
    public function such_a_contribution_is_disclosed_in_the_remarks(): void
    {
        $this->seedRatesWithSicknessAllowance();
        $this->postSlip(3);

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026);

        $this->assertSame('46.71', $certificate['non_deductible_total']);
        $this->assertSame(
            [['name' => 'KTG Krankentaggeld (AN)', 'amount' => '46.71']],
            $certificate['non_deductible_lines'],
        );

        $html = view('exports.salary-certificate-form11', [
            'certificate' => $certificate,
            'organization' => $this->org,
        ])->render();
        $this->assertStringContainsString('KTG Krankentaggeld (AN)', $html);
        $this->assertStringContainsString('46.71', $html);
    }

    #[Test]
    public function withholding_tax_does_not_reduce_the_net_salary_box(): void
    {
        // Withholding tax is box 12 and is reported beside the net salary, not
        // taken off it.
        $this->postSlip(3);

        $slip = $this->employee->salarySlips()->sole();
        $deductions = $slip->deductions;
        $deductions['source_tax'] = '500.00';
        $slip->forceFill(['deductions' => $deductions])->saveQuietly();

        $chiffres = collect(
            app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026)['chiffres']
        )->keyBy('chiffre');

        $this->assertSame('500.00', $chiffres['12']['amount']);
        $this->assertSame('5136.00', $chiffres['11']['amount']);
    }

    /**
     * The four contributions the boxes cover, plus one they do not.
     */
    private function seedRatesWithSicknessAllowance(): void
    {
        foreach ([
            ['AHV/IV/EO (AN)', 'avs_employee', '5.3000', '2270'],
            ['ALV (AN)', 'ac_employee', '1.1000', '2271'],
            ['NBU (AN)', 'aanp_employee', '1.0000', '2272'],
            ['BVG (AN)', 'lpp_employee', '7.0000', '2272'],
            ['KTG Krankentaggeld (AN)', 'ktg_employee', '0.7785', '2272'],
        ] as [$name, $code, $rate, $account]) {
            DeductionRate::create([
                'organization_id' => $this->org->id,
                'name' => $name,
                'code' => $code,
                'rate' => $rate,
                'type' => 'employee',
                'account_code' => $account,
                'expense_account_code' => '5700',
                'is_active' => true,
            ]);
        }
    }

    #[Test]
    public function box_nine_is_the_sum_of_the_three_contributions_it_covers(): void
    {
        $this->postSlip(3);

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026);
        $chiffres = collect($certificate['chiffres'])->keyBy('chiffre');

        $expected = (float) $certificate['avs_employee']
            + (float) $certificate['ac_employee']
            + (float) $certificate['aanp_employee'];

        $this->assertEqualsWithDelta($expected, (float) $chiffres['9']['amount'], 0.001);
    }

    #[Test]
    public function a_flat_expense_sum_is_reported_as_flat_expenses(): void
    {
        $this->employee->update(['expense_allowance' => '250.00']);
        $this->postRun(3);
        $this->postRun(4);

        $chiffres = collect(
            app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026)['chiffres']
        )->keyBy('chiffre');

        $this->assertSame('500.00', $chiffres['13.2.3']['amount'], 'two months of flat expenses');
        $this->assertSame('0.00', $chiffres['13.1.2']['amount'], 'nothing was actual expenses');
    }

    #[Test]
    public function expenses_beyond_the_flat_sum_are_reported_as_actual_expenses(): void
    {
        $this->employee->update(['expense_allowance' => '250.00']);
        $this->postRun(3, reimbursement: '400.00');

        $chiffres = collect(
            app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026)['chiffres']
        )->keyBy('chiffre');

        $this->assertSame('250.00', $chiffres['13.2.3']['amount']);
        $this->assertSame('150.00', $chiffres['13.1.2']['amount']);
    }

    #[Test]
    public function it_omits_the_boxes_this_module_cannot_know_about(): void
    {
        // Printing 0.00 for fringe benefits or board fees would assert that
        // none were paid; payroll has no way of knowing that.
        $this->postSlip(3);

        $printed = collect(
            app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026)['chiffres']
        )->pluck('chiffre');

        foreach (['2.1', '2.2', '2.3', '3', '4', '5', '6', '7'] as $absent) {
            $this->assertNotContains($absent, $printed);
        }
    }

    #[Test]
    public function the_employment_period_narrows_to_the_actual_engagement(): void
    {
        $this->employee->update(['entry_date' => '2026-04-01', 'exit_date' => '2026-09-30']);

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026);

        $this->assertSame('01.04.2026', $certificate['period_from']->format('d.m.Y'));
        $this->assertSame('30.09.2026', $certificate['period_to']->format('d.m.Y'));
    }

    #[Test]
    public function the_period_covers_the_whole_year_for_an_uninterrupted_engagement(): void
    {
        $certificate = app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026);

        $this->assertSame('01.01.2026', $certificate['period_from']->format('d.m.Y'));
        $this->assertSame('31.12.2026', $certificate['period_to']->format('d.m.Y'));
    }

    #[Test]
    public function it_downloads_as_a_pdf(): void
    {
        $this->postSlip(3);

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get("/payroll/employees/{$this->employee->id}/salary-certificate/2026");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    #[Test]
    public function it_refuses_to_render_while_required_details_are_missing(): void
    {
        // An incomplete Form 11 looks official while omitting what the tax
        // authority expects, so it must not be produced at all.
        $this->employee->update(['date_of_birth' => null, 'city' => null]);
        $this->postSlip(3);

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->from("/payroll/employees/{$this->employee->id}")
            ->get("/payroll/employees/{$this->employee->id}/salary-certificate/2026");

        $response->assertRedirect();
        $response->assertSessionHasErrors('certificate');
    }

    #[Test]
    public function the_refusal_names_the_fields_that_are_missing(): void
    {
        $this->employee->update(['date_of_birth' => null]);
        $this->postSlip(3);

        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->from("/payroll/employees/{$this->employee->id}")
            ->get("/payroll/employees/{$this->employee->id}/salary-certificate/2026");

        $this->assertStringContainsString(
            __('validation.attributes.date_of_birth'),
            session('errors')->first('certificate'),
        );
    }

    private function postSlip(int $month): void
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee->fresh(), $month, 2026);
        $slip->save();
        app(PostPayrollAction::class)->execute($slip);
    }

    private function postRun(int $month, ?string $reimbursement = null): void
    {
        $adjustments = $reimbursement === null ? [] : [[
            'employee_id' => $this->employee->id,
            'reimbursement_amount' => $reimbursement,
        ]];

        app(GeneratePayrollRunAction::class)->execute(
            $this->org->id,
            $month,
            2026,
            shouldPost: true,
            employeeIds: [$this->employee->id],
            adjustments: $adjustments,
        );
    }
}
