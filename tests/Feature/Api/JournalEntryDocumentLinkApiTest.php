<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Organizations\Services\CurrentOrganization;
use Illuminate\Testing\TestResponse;
use Tests\Security\SecurityTestCase;

/**
 * The link to an entry's original receipt, set through the API (on create,
 * and afterwards also on posted entries, without a revision).
 */
class JournalEntryDocumentLinkApiTest extends SecurityTestCase
{
    private const DRIVE = 'https://drive.google.com/file/d/abc123/view';

    private string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);

        foreach ([['1020', 'Bank', AccountType::Asset], ['3000', 'Revenue', AccountType::Revenue]] as [$code, $name, $type]) {
            Account::create(['organization_id' => $this->orgA->id, 'code' => $code, 'name' => $name, 'type' => $type->value]);
        }

        $this->tokenA = $this->createApiToken($this->ownerA, $this->orgA);
    }

    private function postEntry(array $extra = []): TestResponse
    {
        return $this->withToken($this->tokenA)->postJson('/api/v1/journal-entries', [
            'date' => '2026-08-21',
            'reference' => 'BAN-20260821-1200',
            'description' => 'Membership income',
            'status' => 'posted',
            'lines' => [
                ['account_code' => '1020', 'debit' => '500.00', 'credit' => '0.00'],
                ['account_code' => '3000', 'debit' => '0.00', 'credit' => '500.00'],
            ],
            ...$extra,
        ]);
    }

    public function test_an_entry_is_created_with_its_receipt_link(): void
    {
        $this->postEntry(['document_url' => self::DRIVE])
            ->assertCreated()
            ->assertJsonPath('data.document_url', self::DRIVE);
    }

    public function test_a_posted_entry_takes_a_receipt_link_without_a_revision(): void
    {
        $id = $this->postEntry()->assertCreated()->json('data.id');

        $this->withToken($this->tokenA)
            ->putJson("/api/v1/journal-entries/{$id}/document", ['document_url' => self::DRIVE])
            ->assertOk()
            ->assertJsonPath('data.document_url', self::DRIVE)
            ->assertJsonPath('data.revision', 1);

        $this->withToken($this->tokenA)
            ->putJson("/api/v1/journal-entries/{$id}/document", ['document_url' => null])
            ->assertOk()
            ->assertJsonPath('data.document_url', null);

        $this->assertNull(JournalEntry::find($id)->document_url);
    }

    public function test_only_web_links_are_accepted(): void
    {
        $id = $this->postEntry()->assertCreated()->json('data.id');

        $this->withToken($this->tokenA)
            ->putJson("/api/v1/journal-entries/{$id}/document", ['document_url' => 'javascript:alert(1)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_url');

        $this->postEntry(['reference' => 'BAN-20260821-1201', 'document_url' => 'file:///etc/passwd'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_url');
    }

    public function test_a_viewer_cannot_set_a_receipt_link(): void
    {
        $id = $this->postEntry()->assertCreated()->json('data.id');
        $viewer = $this->createUserInOrg($this->orgA, 'viewer');
        $token = $this->createApiToken($viewer, $this->orgA);
        $this->app['auth']->forgetGuards();

        $this->assertDenied($this->withToken($token)
            ->putJson("/api/v1/journal-entries/{$id}/document", ['document_url' => self::DRIVE]));
        $this->assertNull(JournalEntry::find($id)->document_url);
    }
}
