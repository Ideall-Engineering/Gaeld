<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Actions\CreateInvoiceAction;
use App\Domains\Invoicing\Actions\FinalizeInvoiceAction;
use App\Domains\Invoicing\DTOs\CreateInvoiceData;
use App\Domains\Invoicing\DTOs\InvoiceLineData;
use App\Domains\Invoicing\DTOs\RecordPaymentData;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Enums\PaymentMethod;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InstallmentPlanService;
use App\Domains\Invoicing\Services\InvoiceAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Spec 013: an invoice paid in rates. The last rate carries the rest,
 * payments fill the rates in order and the invoice falls due with the
 * first rate not yet covered.
 */
class InstallmentPlanServiceTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $standard;

    private InstallmentPlanService $plans;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02 10:00:00');
        $this->setUpOrganization();

        foreach ([
            ['1020', AccountType::Asset],
            ['1100', AccountType::Asset],
            ['2200', AccountType::Liability],
            ['3000', AccountType::Revenue],
        ] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->org->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type->value,
            ]);
        }

        $this->standard = VatRate::create([
            'organization_id' => $this->org->id,
            'name' => 'Normal',
            'rate' => 8.10,
            'code' => 'NORMAL',
            'is_default' => true,
        ]);

        $this->customer = Contact::create([
            'organization_id' => $this->org->id,
            'name' => 'Kunde AG',
        ]);

        $this->plans = app(InstallmentPlanService::class);
    }

    private function draft(): Invoice
    {
        return app(CreateInvoiceAction::class)->execute(new CreateInvoiceData(
            organizationId: $this->org->id,
            customerId: $this->customer->id,
            number: 'INV-PLAN-1',
            issueDate: '2026-11-02',
            dueDate: '2026-12-02',
            currency: 'CHF',
            notes: null,
            paymentTerms: null,
            lines: [InvoiceLineData::fromArray([
                'description' => 'Beratung',
                'quantity' => 10,
                'unit_price' => 150.00,
                'vat_rate_id' => $this->standard->id,
            ])],
        ));
    }

    /** @return list<array{due_date: string, amount: string}> */
    private function threeRates(): array
    {
        return [
            ['due_date' => '2026-11-30', 'amount' => '600.00'],
            ['due_date' => '2026-12-31', 'amount' => '600.00'],
            ['due_date' => '2027-01-31', 'amount' => '1.00'],
        ];
    }

    private function withPlan(): Invoice
    {
        $invoice = $this->draft();
        $this->plans->sync($invoice, $this->threeRates());

        return app(FinalizeInvoiceAction::class)->execute($invoice->fresh());
    }

    private function pay(Invoice $invoice, string $amount): void
    {
        app(InvoiceAccountingService::class)->recordPayment($invoice, new RecordPaymentData(
            amount: $amount,
            paymentDate: now()->toDateString(),
            paymentMethod: PaymentMethod::Bank,
            reference: null,
        ));
    }

    public function test_the_last_rate_carries_the_rest(): void
    {
        $invoice = $this->draft();
        $this->assertSame('1621.50', (string) $invoice->total);

        $this->plans->sync($invoice, $this->threeRates());

        $rates = $invoice->installments()->get();
        $this->assertSame(['600.00', '600.00', '421.50'], $rates->map(fn ($rate) => (string) $rate->amount)->all());
        $this->assertSame([1, 2, 3], $rates->pluck('position')->all());
        $this->assertSame('2026-11-30', $invoice->fresh()->due_date->toDateString());
        $this->assertTrue($invoice->fresh()->hasPaymentPlan());
    }

    public function test_a_plan_breaking_the_rules_is_refused(): void
    {
        $invoice = $this->draft();
        $month = fn (int $i) => ['due_date' => now()->addMonths($i)->toDateString(), 'amount' => '10.00'];

        $broken = [
            'one rate' => [$month(1)],
            'thirteen rates' => array_map($month, range(1, 13)),
            'dates not ascending' => [$month(2), $month(1), $month(3)],
            'same date twice' => [$month(1), $month(1)],
            'first rate before the invoice' => [['due_date' => '2026-11-01', 'amount' => '10.00'], $month(1)],
            'nothing left for the last rate' => [
                ['due_date' => '2026-11-30', 'amount' => '1000.00'],
                ['due_date' => '2026-12-31', 'amount' => '621.50'],
                ['due_date' => '2027-01-31', 'amount' => null],
            ],
            'a rate of zero' => [['due_date' => '2026-11-30', 'amount' => '0.00'], $month(2)],
        ];

        foreach ($broken as $case => $rates) {
            try {
                $this->plans->sync($invoice, $rates);
                $this->fail("Plan accepted: {$case}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('installments', $e->errors(), $case);
            }
            $this->assertSame(0, $invoice->installments()->count(), $case);
        }
    }

    public function test_an_empty_plan_removes_it(): void
    {
        $invoice = $this->draft();
        $this->plans->sync($invoice, $this->threeRates());

        $this->plans->sync($invoice, []);

        $this->assertSame(0, $invoice->installments()->count());
        $this->assertFalse($invoice->fresh()->hasPaymentPlan());
    }

    public function test_payments_fill_the_rates_in_order(): void
    {
        $invoice = $this->withPlan();
        $this->pay($invoice, '900.00');

        $states = $this->plans->states($invoice->fresh());

        $this->assertSame(['600.00', '300.00', '0.00'], array_column($states, 'paid'));
        $this->assertSame(['0.00', '300.00', '421.50'], array_column($states, 'open'));
        $this->assertSame([false, false, false], array_column($states, 'overdue'));
    }

    public function test_refresh_moves_the_due_date_and_status(): void
    {
        $invoice = $this->withPlan();
        $this->travelTo('2026-12-05 10:00:00');
        $invoice->update(['status' => InvoiceStatus::Overdue]);
        $this->assertTrue($this->plans->states($invoice->fresh())[0]['overdue']);

        $this->pay($invoice->fresh(), '600.00');
        $this->plans->refresh($invoice->fresh());

        $invoice->refresh();
        $this->assertSame('2026-12-31', $invoice->due_date->toDateString());
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
    }

    public function test_once_every_rate_is_covered_the_last_rate_is_the_due_date(): void
    {
        $invoice = $this->withPlan();
        $this->pay($invoice, '1621.50');

        $this->plans->refresh($invoice->fresh());

        $invoice->refresh();
        $this->assertSame('2027-01-31', $invoice->due_date->toDateString());
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
    }

    public function test_an_invoice_without_a_plan_is_left_alone(): void
    {
        $invoice = app(FinalizeInvoiceAction::class)->execute($this->draft());

        $this->plans->refresh($invoice);

        $this->assertSame('2026-12-02', $invoice->fresh()->due_date->toDateString());
        $this->assertSame([], $this->plans->states($invoice));
    }
}
