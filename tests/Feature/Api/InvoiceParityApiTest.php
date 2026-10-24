<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\RecurringInvoice;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Projects\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Security\SecurityTestCase;

/**
 * Spec 011: everything the invoice pages can do, the API can do too.
 */
class InvoiceParityApiTest extends SecurityTestCase
{
    private string $tokenA;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);
        VatRate::create(['organization_id' => $this->orgA->id, 'name' => 'Standard', 'rate' => 8.10, 'code' => 'NORMAL', 'is_default' => true]);
        $this->customer = Contact::create(['organization_id' => $this->orgA->id, 'name' => 'Muster AG']);
        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
    }

    private function draft(): string
    {
        return $this->withToken($this->tokenA)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-10-20',
            'lines' => [
                ['description' => 'Workshop', 'quantity' => 2, 'unit' => 'Pauschal', 'unit_price' => 500, 'vat_rate_code' => 'NORMAL'],
                ['type' => 'discount', 'discount_type' => 'percentage', 'description' => 'Rabatt', 'quantity' => 1, 'unit_price' => 10, 'vat_rate_code' => 'NORMAL'],
                ['type' => 'text', 'description' => 'Termine nach Vereinbarung'],
            ],
        ], ['Idempotency-Key' => 'draft-'.uniqid()])->assertCreated()->json('data.id');
    }

    public function test_an_invoice_takes_discount_and_text_lines_and_a_job(): void
    {
        $modules = $this->orgA->enabled_modules ?? [];
        $modules['projects'] = true;
        $this->orgA->update(['enabled_modules' => $modules]);
        $job = Project::create(['organization_id' => $this->orgA->id, 'code' => 'P-1', 'name' => 'Workshop', 'kind' => 'project', 'status' => 'in_progress']);

        $this->withToken($this->tokenA)->postJson('/api/v1/invoices', [
            'customer_id' => $this->customer->uuid,
            'issue_date' => '2026-10-20',
            'project_id' => $job->id,
            'lines' => [
                ['description' => 'Workshop', 'quantity' => 2, 'unit_price' => 500, 'vat_rate_code' => 'NORMAL'],
                ['type' => 'discount', 'discount_type' => 'percentage', 'description' => 'Rabatt', 'quantity' => 1, 'unit_price' => 10, 'vat_rate_code' => 'NORMAL'],
                ['type' => 'text', 'description' => 'Termine nach Vereinbarung'],
            ],
        ], ['Idempotency-Key' => 'with-job'])->assertCreated()
            ->assertJsonPath('data.project_id', $job->id)
            ->assertJsonPath('data.subtotal', '900.00')
            ->assertJsonPath('data.total', '972.90')
            ->assertJsonCount(3, 'data.lines');
    }

    public function test_an_invoice_is_duplicated_and_carries_a_receipt(): void
    {
        Storage::fake('local');
        $id = $this->draft();

        $copy = $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/duplicate")
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertNotSame($id, $copy['id']);
        $this->assertSame(['Pauschal', null, null], array_column($copy['lines'], 'unit'));
        $this->assertSame(['item', 'discount', 'text'], array_column($copy['lines'], 'type'));

        $this->withToken($this->tokenA)->post("/api/v1/invoices/{$id}/justificatif", [
            'file' => UploadedFile::fake()->create('beleg.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.has_justificatif', true);
        $path = Invoice::findOrFail($id)->justificatif_path;
        Storage::disk('local')->assertExists($path);

        $this->withToken($this->tokenA)->get("/api/v1/invoices/{$id}/justificatif")->assertOk();
        $this->withToken($this->tokenA)->deleteJson("/api/v1/invoices/{$id}/justificatif")->assertNoContent();
        $this->assertNull(Invoice::findOrFail($id)->justificatif_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_customer_address_is_refreshed_and_a_draft_is_not_reverted(): void
    {
        $id = $this->draft();
        $this->customer->update(['name' => 'Muster Holding AG']);

        $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/refresh-customer")->assertOk();
        $this->assertSame('Muster Holding AG', Invoice::findOrFail($id)->customer_snapshot['name']);

        // As on the web, only an issued invoice goes back to draft.
        $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/revert-to-draft")->assertForbidden();
    }

    public function test_an_issued_invoice_is_reverted_corrected_and_finalized_again(): void
    {
        foreach (['1100' => AccountType::Asset, '3000' => AccountType::Revenue, '2200' => AccountType::Liability] as $code => $type) {
            Account::create(['organization_id' => $this->orgA->id, 'code' => $code, 'name' => "Konto {$code}", 'type' => $type->value]);
        }
        $id = $this->draft();
        $this->withToken($this->tokenA)->patchJson("/api/v1/invoices/{$id}", ['due_date' => '2026-11-19'])->assertOk();
        $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/finalize")->assertOk();

        $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/revert-to-draft")
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->withToken($this->tokenA)->patchJson("/api/v1/invoices/{$id}", ['notes' => 'Korrigiert'])->assertOk();

        $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/finalize")
            ->assertOk()->assertJsonPath('data.status', 'sent');
        $invoice = Invoice::findOrFail($id);
        $this->assertSame($invoice->number.'-v2', $invoice->journalEntry->reference);

        // A retry within the round still replays; the next round is new again.
        $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/finalize")->assertOk();
        $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/revert-to-draft")
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->withToken($this->tokenA)->postJson("/api/v1/invoices/{$id}/finalize")->assertOk();
        $this->assertSame($invoice->number.'-v3', $invoice->fresh()->journalEntry->reference);
    }

    public function test_a_cancelled_invoice_is_removed_for_good(): void
    {
        $invoice = Invoice::factory()->create(['organization_id' => $this->orgA->id, 'status' => InvoiceStatus::Cancelled]);

        $this->withToken($this->tokenA)->deleteJson("/api/v1/invoices/{$invoice->id}/purge")->assertNoContent();
        $this->assertNull(Invoice::withTrashed()->find($invoice->id));
    }

    public function test_recurring_invoices_are_kept_over_the_api(): void
    {
        $id = $this->withToken($this->tokenA)->postJson('/api/v1/recurring-invoices', [
            'customer_id' => $this->customer->uuid,
            'frequency' => 'monthly',
            'next_issue_date' => now()->addMonth()->toDateString(),
            'lines' => [['description' => 'Wartung', 'quantity' => 1, 'unit' => 'Monat', 'unit_price' => 200, 'vat_rate_code' => 'NORMAL']],
        ])->assertCreated()
            ->assertJsonPath('data.customer.id', $this->customer->uuid)
            ->assertJsonPath('data.lines.0.unit', 'Monat')
            ->json('data.id');

        $this->withToken($this->tokenA)->getJson('/api/v1/recurring-invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->withToken($this->tokenA)->postJson("/api/v1/recurring-invoices/{$id}/pause")->assertOk()->assertJsonPath('data.is_active', false);
        $this->withToken($this->tokenA)->patchJson("/api/v1/recurring-invoices/{$id}", ['frequency' => 'quarterly'])
            ->assertOk()->assertJsonPath('data.frequency', 'quarterly')->assertJsonPath('data.lines.0.description', 'Wartung');
        $this->withToken($this->tokenA)->deleteJson("/api/v1/recurring-invoices/{$id}")->assertNoContent();
        $this->assertSame(0, RecurringInvoice::count());
    }

    public function test_catalog_items_are_kept_over_the_api(): void
    {
        $id = $this->withToken($this->tokenA)->postJson('/api/v1/invoice-catalog-items', ['name' => 'Beratung', 'default_unit_price' => 150])
            ->assertCreated()->json('data.id');
        $this->withToken($this->tokenA)->getJson('/api/v1/invoice-catalog-items')->assertOk()->assertJsonPath('data.0.name', 'Beratung');
        $this->withToken($this->tokenA)->deleteJson("/api/v1/invoice-catalog-items/{$id}")->assertNoContent();
    }

    public function test_another_organisation_cannot_touch_the_invoice(): void
    {
        $id = $this->draft();
        $tokenB = $this->createApiToken($this->ownerB, $this->orgB);

        $this->assertDenied($this->withToken($tokenB)->postJson("/api/v1/invoices/{$id}/duplicate"));
        $this->assertDenied($this->withToken($tokenB)->deleteJson("/api/v1/invoices/{$id}/justificatif"));
    }
}
