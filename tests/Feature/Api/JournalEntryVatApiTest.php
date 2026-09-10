<?php

namespace Tests\Feature\Api;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Enums\VatEntryType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Models\VatEntry;
use App\Domains\Accounting\Models\VatRate;
use App\Domains\Organizations\Services\CurrentOrganization;
use Tests\Security\SecurityTestCase;

/**
 * Covers the VAT-carrying journal entry API: the explicit form, the shorthand
 * form with a Banana-style code, and the errors both can produce.
 */
class JournalEntryVatApiTest extends SecurityTestCase
{
    private string $token;

    private VatRate $normalRate;

    private VatRate $exemptRate;

    protected function setUp(): void
    {
        parent::setUp();

        config(['features.api_access' => true]);
        app(CurrentOrganization::class)->set($this->orgA);

        $accounts = [
            ['1020', 'Bank', AccountType::Asset],
            ['1170', 'Vorsteuer', AccountType::Asset],
            ['2200', 'Umsatzsteuer', AccountType::Liability],
            ['2202', 'Bezugsteuer geschuldet', AccountType::Liability],
            ['3000', 'Dienstleistungserlöse', AccountType::Revenue],
            ['6033', 'Betriebs Software', AccountType::Expense],
            ['1520', 'Informatik', AccountType::Asset],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            Account::create([
                'organization_id' => $this->orgA->id,
                'code' => $code,
                'name' => $name,
                'type' => $type->value,
            ]);
        }

        $this->normalRate = VatRate::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Standard Rate',
            'code' => 'NORMAL',
            'rate' => '8.10',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->exemptRate = VatRate::create([
            'organization_id' => $this->orgA->id,
            'name' => 'Exempt',
            'code' => 'EXEMPT',
            'rate' => '0.00',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->token = $this->createApiToken($this->ownerA, $this->orgA);
    }

    // ──────────────────────────────────────────────────────────────
    //  Explicit form
    // ──────────────────────────────────────────────────────────────

    public function test_explicit_lines_create_a_vat_entry_and_expose_it_again(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-01-05',
            'reference' => 'BAN-20260105-1069',
            'description' => 'Google Cloud',
            'status' => 'posted',
            'lines' => [
                [
                    'account_code' => '6033',
                    'debit' => '0.70',
                    'credit' => '0.00',
                    'vat_rate_id' => $this->normalRate->uuid,
                    'vat_type' => 'input',
                    'vat_amount' => '0.06',
                ],
                ['account_code' => '1170', 'debit' => '0.06', 'credit' => '0.00'],
                ['account_code' => '1020', 'debit' => '0.00', 'credit' => '0.76'],
            ],
        ]);

        $response->assertCreated();

        $entry = JournalEntry::where('reference', 'BAN-20260105-1069')->firstOrFail();
        $vatEntries = VatEntry::where('journal_entry_id', $entry->id)->get();

        $this->assertCount(1, $vatEntries);
        $this->assertSame(VatEntryType::Input, $vatEntries->first()->type);
        $this->assertSame('0.70', (string) $vatEntries->first()->base_amount);
        $this->assertSame('0.06', (string) $vatEntries->first()->vat_amount);

        // The line relation has no defined order, so find the line by account
        // rather than by position.
        $lines = $this->withToken($this->token)
            ->getJson("/api/v1/journal-entries/{$entry->id}")
            ->assertOk()
            ->json('data.lines');

        $expenseLine = collect($lines)->firstWhere('account_code', '6033');

        $this->assertSame('input', $expenseLine['vat_type']);
        $this->assertSame('0.06', $expenseLine['vat_amount']);
        $this->assertSame($this->normalRate->uuid, $expenseLine['vat_rate_id']);
    }

    public function test_a_vat_type_without_a_rate_is_rejected(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-01-05',
            'status' => 'posted',
            'reference' => 'NO-RATE',
            'lines' => [
                ['account_code' => '6033', 'debit' => '0.70', 'credit' => '0.00', 'vat_type' => 'input'],
                ['account_code' => '1020', 'debit' => '0.00', 'credit' => '0.70'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.vat_rate_id');
    }

    public function test_a_vat_rate_of_another_organization_is_rejected(): void
    {
        $foreignRate = VatRate::create([
            'organization_id' => $this->orgB->id,
            'name' => 'Standard Rate',
            'code' => 'NORMAL',
            'rate' => '8.10',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-01-05',
            'status' => 'posted',
            'reference' => 'FOREIGN-RATE',
            'lines' => [
                [
                    'account_code' => '6033',
                    'debit' => '0.70',
                    'credit' => '0.00',
                    'vat_rate_id' => $foreignRate->uuid,
                    'vat_type' => 'input',
                    'vat_amount' => '0.06',
                ],
                ['account_code' => '1020', 'debit' => '0.00', 'credit' => '0.70'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0.vat_rate_id');
    }

    // ──────────────────────────────────────────────────────────────
    //  Shorthand form
    // ──────────────────────────────────────────────────────────────

    public function test_shorthand_m81_produces_the_same_lines_as_the_explicit_form(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-02-09',
            'reference' => 'BAN-20260209-1074',
            'description' => 'Lourens Systems',
            'status' => 'posted',
            'lines' => [[
                'account_code' => '6033',
                'contra_account_code' => '1020',
                'gross' => '60.00',
                'vat_code' => 'M81',
            ]],
        ])->assertCreated();

        $entry = JournalEntry::where('reference', 'BAN-20260209-1074')->firstOrFail();
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($line) => $line->account->code);

        $this->assertSame('55.50', (string) $lines['6033']->debit);
        $this->assertSame('4.50', (string) $lines['1170']->debit);
        $this->assertSame('60.00', (string) $lines['1020']->credit);
        $this->assertSame(VatEntryType::Input, $lines['6033']->vat_type);

        $vatEntry = VatEntry::where('journal_entry_id', $entry->id)->sole();
        $this->assertSame('55.50', (string) $vatEntry->base_amount);
        $this->assertSame('4.50', (string) $vatEntry->vat_amount);
    }

    public function test_shorthand_i81_books_the_investment_figure(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-02-11',
            'reference' => 'BAN-20260211-1018',
            'status' => 'posted',
            'lines' => [[
                'account_code' => '1520',
                'contra_account_code' => '1020',
                'gross' => '86.90',
                'vat_code' => 'I81',
            ]],
        ])->assertCreated();

        $entry = JournalEntry::where('reference', 'BAN-20260211-1018')->firstOrFail();
        $vatEntry = VatEntry::where('journal_entry_id', $entry->id)->sole();

        $this->assertSame(VatEntryType::InputInvestment, $vatEntry->type);
        $this->assertSame('80.39', (string) $vatEntry->base_amount);
        $this->assertSame('6.51', (string) $vatEntry->vat_amount);
    }

    public function test_shorthand_v81_splits_the_gross_revenue(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-01-07',
            'reference' => 'BAN-20260107-1001',
            'status' => 'posted',
            'lines' => [[
                'account_code' => '3000',
                'contra_account_code' => '1020',
                'gross' => '259.44',
                'vat_code' => 'V81',
            ]],
        ])->assertCreated();

        $entry = JournalEntry::where('reference', 'BAN-20260107-1001')->firstOrFail();
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($line) => $line->account->code);

        $this->assertSame('259.44', (string) $lines['1020']->debit);
        $this->assertSame('240.00', (string) $lines['3000']->credit);
        $this->assertSame('19.44', (string) $lines['2200']->credit);

        $vatEntry = VatEntry::where('journal_entry_id', $entry->id)->sole();
        $this->assertSame(VatEntryType::Output, $vatEntry->type);
        $this->assertSame('19.44', (string) $vatEntry->vat_amount);
    }

    public function test_shorthand_b81_books_acquisition_tax_over_account_2202(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-04-13',
            'reference' => 'BAN-20260413-1084',
            'description' => 'Anthropic Ireland',
            'status' => 'posted',
            'lines' => [[
                'account_code' => '6033',
                'contra_account_code' => '1020',
                'gross' => '43.40',
                'vat_code' => 'B81',
            ]],
        ])->assertCreated();

        $entry = JournalEntry::where('reference', 'BAN-20260413-1084')->firstOrFail();
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($line) => $line->account->code);

        // The foreign supplier charges no VAT, so the bank pays the net amount.
        $this->assertSame('43.40', (string) $lines['6033']->debit);
        $this->assertSame('43.40', (string) $lines['1020']->credit);
        $this->assertSame('3.52', (string) $lines['1170']->debit);
        $this->assertSame('3.52', (string) $lines['2202']->credit);

        $types = VatEntry::where('journal_entry_id', $entry->id)->pluck('type')->all();
        $this->assertEqualsCanonicalizing(
            [VatEntryType::Acquisition, VatEntryType::Input],
            $types,
        );
    }

    public function test_shorthand_v0_keeps_exempt_turnover_in_chiffre_200(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-04-30',
            'reference' => 'BAN-20260430-1051',
            'status' => 'posted',
            'lines' => [[
                'account_code' => '3000',
                'contra_account_code' => '1020',
                'gross' => '4484.00',
                'vat_code' => 'V0',
                'vat_figure' => '230',
            ]],
        ])->assertCreated();

        $entry = JournalEntry::where('reference', 'BAN-20260430-1051')->firstOrFail();
        $vatEntry = VatEntry::where('journal_entry_id', $entry->id)->sole();

        $this->assertSame(VatEntryType::Output, $vatEntry->type);
        $this->assertSame('4484.00', (string) $vatEntry->base_amount);
        $this->assertSame('0.00', (string) $vatEntry->vat_amount);
        $this->assertSame('230', $vatEntry->figure);
    }

    public function test_shorthand_m0_creates_no_vat_entry(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-03-30',
            'reference' => 'BAN-20260330-1032',
            'status' => 'posted',
            'lines' => [[
                'account_code' => '6033',
                'contra_account_code' => '1020',
                'gross' => '40.00',
                'vat_code' => 'M0',
            ]],
        ])->assertCreated();

        $entry = JournalEntry::where('reference', 'BAN-20260330-1032')->firstOrFail();

        $this->assertSame(0, VatEntry::where('journal_entry_id', $entry->id)->count());
        $this->assertCount(2, $entry->lines);
    }

    // ──────────────────────────────────────────────────────────────
    //  Errors
    // ──────────────────────────────────────────────────────────────

    public function test_an_unknown_vat_code_is_answered_with_a_stable_error_code(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-01-05',
            'reference' => 'UNKNOWN-CODE',
            'status' => 'posted',
            'lines' => [[
                'account_code' => '6033',
                'contra_account_code' => '1020',
                'gross' => '10.00',
                'vat_code' => 'X99',
            ]],
        ])->assertStatus(422)->assertJsonPath('code', 'unknown_vat_code');

        $this->assertNull(JournalEntry::where('reference', 'UNKNOWN-CODE')->first());
    }

    public function test_mixing_shorthand_and_explicit_lines_is_rejected(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-01-05',
            'reference' => 'MIXED',
            'status' => 'posted',
            'lines' => [
                [
                    'account_code' => '6033',
                    'contra_account_code' => '1020',
                    'gross' => '10.00',
                    'vat_code' => 'M81',
                    'debit' => '10.00',
                    'credit' => '0.00',
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('lines.0');
    }

    public function test_a_repeated_shorthand_call_creates_only_one_entry(): void
    {
        $payload = [
            'date' => '2026-02-10',
            'reference' => 'BAN-20260210-1075',
            'status' => 'posted',
            'lines' => [[
                'account_code' => '6033',
                'contra_account_code' => '1020',
                'gross' => '14.90',
                'vat_code' => 'M81',
            ]],
        ];

        $first = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'shorthand-once')
            ->postJson('/api/v1/journal-entries', $payload);
        $first->assertCreated();

        $second = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', 'shorthand-once')
            ->postJson('/api/v1/journal-entries', $payload);
        $second->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, JournalEntry::where('reference', 'BAN-20260210-1075')->count());
    }

    public function test_a_shorthand_draft_creates_no_vat_entry_until_posted(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/journal-entries', [
            'date' => '2026-02-10',
            'reference' => 'BAN-DRAFT',
            'status' => 'draft',
            'lines' => [[
                'account_code' => '6033',
                'contra_account_code' => '1020',
                'gross' => '60.00',
                'vat_code' => 'M81',
            ]],
        ])->assertCreated();

        $entry = JournalEntry::where('reference', 'BAN-DRAFT')->firstOrFail();
        $this->assertSame(0, VatEntry::where('journal_entry_id', $entry->id)->count());

        $this->withToken($this->token)
            ->postJson("/api/v1/journal-entries/{$entry->id}/post")
            ->assertOk();

        $this->assertSame(1, VatEntry::where('journal_entry_id', $entry->id)->count());
    }
}
