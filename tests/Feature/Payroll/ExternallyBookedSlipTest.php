<?php

namespace Tests\Feature\Payroll;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Payroll\Actions\GenerateSalaryCertificateAction;
use App\Domains\Payroll\Actions\PostPayrollAction;
use App\Domains\Payroll\Exceptions\ExternallyBookedSlipException;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\SalarySlip;
use App\Domains\Payroll\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * An employer who ran part of the year by hand could not complete the salary
 * certificate: only posted slips counted, and posting those months again would
 * have booked the salary twice. A slip can now record such a month — and is
 * refused at the ledger, not merely hidden from the button that posts it.
 */
class ExternallyBookedSlipTest extends TestCase
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
            'entry_date' => '2026-01-01',
        ]);
    }

    private function slip(int $month, bool $external): SalarySlip
    {
        $slip = app(PayrollCalculator::class)->calculate($this->employee, $month, 2026);
        $slip->booked_externally = $external;
        $slip->save();

        return $slip;
    }

    // ──────────────────────────────────────────────────────────────

    public function test_an_externally_booked_slip_is_refused_at_the_ledger(): void
    {
        $slip = $this->slip(3, external: true);

        $this->expectException(ExternallyBookedSlipException::class);

        app(PostPayrollAction::class)->execute($slip);
    }

    public function test_the_post_endpoint_refuses_it_too(): void
    {
        $slip = $this->slip(3, external: true);

        $this->actAsOrg()
            ->postJson("/payroll/salary-slips/{$slip->id}/post")
            ->assertStatus(422);

        $this->assertSame(0, JournalEntry::count());
        $this->assertNull($slip->fresh()->posted_at);
    }

    public function test_a_run_marked_externally_booked_writes_no_journal_entry(): void
    {
        $this->actAsOrg()->postJson('/payroll/run', [
            'month' => 3,
            'year' => 2026,
            'employee_ids' => [$this->employee->id],
            'booked_externally' => true,
        ])->assertOk();

        $this->assertTrue(SalarySlip::sole()->booked_externally);
        $this->assertSame('external', SalarySlip::sole()->status);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_the_salary_certificate_counts_a_month_booked_by_hand(): void
    {
        // March to May by hand, June posted through the module.
        foreach ([3, 4, 5] as $month) {
            $this->slip($month, external: true);
        }
        app(PostPayrollAction::class)->execute($this->slip(6, external: false));

        $certificate = app(GenerateSalaryCertificateAction::class)->execute($this->employee, 2026);

        $this->assertSame(4, $certificate['months_covered']);
        $this->assertSame('20000.00', $certificate['gross_salary']);
    }
}
