<?php

namespace Tests\Feature\Invoicing;

use App\Domains\Contacts\Models\Contact;
use App\Domains\Invoicing\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * A receipt attached in the invoice form stays with the invoice; replacing
 * it removes the earlier file.
 */
class InvoiceJustificatifTest extends TestCase
{
    use RefreshDatabase, WithAuthenticatedOrganization;

    public function test_an_attached_receipt_is_kept_and_replaced(): void
    {
        $this->setUpOrganization();
        Storage::fake('local');
        $customer = Contact::create(['organization_id' => $this->org->id, 'name' => 'Muster AG']);
        $line = ['type' => 'item', 'description' => 'Beratung', 'quantity' => 1, 'unit_price' => 100];

        $this->actAsOrg()->post('/invoices', [
            'customer_id' => $customer->id, 'issue_date' => '2026-10-20', 'lines' => [$line],
            'justificatif' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::firstOrFail();
        $first = $invoice->justificatif_path;
        $this->assertNotNull($first);
        Storage::disk('local')->assertExists($first);

        $this->actAsOrg()->post("/invoices/{$invoice->id}", [
            '_method' => 'PUT', 'customer_id' => $customer->id, 'number' => $invoice->number, 'issue_date' => '2026-10-20', 'lines' => [$line],
            'justificatif' => UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $second = $invoice->fresh()->justificatif_path;
        $this->assertNotNull($second);
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertExists($second);
        Storage::disk('local')->assertMissing($first);
    }
}
