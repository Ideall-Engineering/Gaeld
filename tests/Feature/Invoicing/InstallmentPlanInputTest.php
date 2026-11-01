<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Spec 013: the payment plan is entered with the draft on the invoice page,
 * and the last rate follows the total when the lines change.
 */
class InstallmentPlanInputTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    private Contact $customer;

    private VatRate $vatRate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-02 10:00:00');
        $this->setUpOrganization();

        foreach (['1020' => AccountType::Asset, '1100' => AccountType::Asset, '2200' => AccountType::Liability, '3000' => AccountType::Revenue] as $code => $type) {
            Account::create(['organization_id' => $this->org->id, 'code' => (string) $code, 'name' => "Konto {$code}", 'type' => $type->value]);
        }
        $this->vatRate = VatRate::create(['organization_id' => $this->org->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Kunde AG']);
    }

    /** @return array<string, mixed> */
    private function payload(int $quantity = 10, ?array $installments = null): array
    {
        return array_filter([
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-11-02',
            'currency' => 'CHF',
            'lines' => [[
                'description' => 'Beratung',
                'quantity' => $quantity,
                'unit_price' => 150.00,
                'vat_rate_id' => $this->vatRate->id,
            ]],
            'installments' => $installments,
        ], fn ($value) => $value !== null);
    }

    /** @return list<array{due_date: string, amount: string|null}> */
    private function rates(): array
    {
        return [
            ['due_date' => '2026-11-30', 'amount' => '600.00'],
            ['due_date' => '2026-12-31', 'amount' => '600.00'],
            ['due_date' => '2027-01-31', 'amount' => null],
        ];
    }

    private function draftWithPlan(): Invoice
    {
        $this->actAsOrg()->post(route('invoices.store'), $this->payload(installments: $this->rates()))
            ->assertSessionHasNoErrors();

        return Invoice::where('organization_id', $this->org->id)->sole();
    }

    /** @return list<string> */
    private function amounts(Invoice $invoice): array
    {
        return $invoice->installments()->get()->map(fn ($rate) => (string) $rate->amount)->all();
    }

    public function test_a_draft_is_saved_with_a_plan_through_the_page(): void
    {
        $invoice = $this->draftWithPlan();

        $this->assertSame(['600.00', '600.00', '421.50'], $this->amounts($invoice));
        $this->assertSame('2026-11-30', $invoice->due_date->toDateString());
    }

    public function test_changing_the_lines_of_a_draft_moves_the_rest_to_the_last_rate(): void
    {
        $invoice = $this->draftWithPlan();

        $this->actAsOrg()->put(route('invoices.update', $invoice), [
            ...$this->payload(12),
            'number' => $invoice->number,
        ])->assertSessionHasNoErrors();

        $this->assertSame('1945.80', (string) $invoice->fresh()->total);
        $this->assertSame(['600.00', '600.00', '745.80'], $this->amounts($invoice));
    }

    public function test_a_rest_of_zero_is_refused(): void
    {
        $invoice = $this->draftWithPlan();

        $this->actAsOrg()->put(route('invoices.update', $invoice), [
            ...$this->payload(7),
            'number' => $invoice->number,
        ])->assertSessionHasErrors('installments');

        $this->assertSame('1621.50', (string) $invoice->fresh()->total);
        $this->assertSame(['600.00', '600.00', '421.50'], $this->amounts($invoice));
    }

    public function test_an_empty_plan_from_the_page_removes_it(): void
    {
        $invoice = $this->draftWithPlan();

        $this->actAsOrg()->put(route('invoices.update', $invoice), [
            ...$this->payload(),
            'payment_plan' => false,
            'due_date' => '2026-12-02',
            'number' => $invoice->number,
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $this->amounts($invoice));
        $this->assertSame('2026-12-02', $invoice->fresh()->due_date->toDateString());
    }
}
