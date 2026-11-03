<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Models\Acquisition;
use App\Domains\Projects\Services\AcquisitionReceiptCheckService;
use App\Domains\Projects\Services\AcquisitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Purchases booked without a word on whether they are an acquisition show
 * on the list of acquisitions until one is recorded or they are set aside.
 */
class AcquisitionReceiptsTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Account $inventory;

    private Account $vat;

    private Account $software;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->inventory = $this->makeAccount('1500', 'Equipment', AccountType::Asset);
        $this->vat = $this->makeAccount('1170', 'Input VAT', AccountType::Asset);
        $this->software = $this->makeAccount('6034', 'Software', AccountType::Expense);
    }

    public function test_purchases_without_a_decision_are_listed(): void
    {
        $printer = $this->purchase('WISE-1', 'Bambu Lab printer', $this->inventory, '1000.00', '81.00');
        $licence = $this->purchase('BAN-2', 'Adobe licence', $this->software, '50.00', '0.00');
        $linked = $this->purchase('BAN-3', 'Display', $this->inventory, '1433.86', '116.14');
        app(AcquisitionService::class)->save(null, $this->organization->id, [
            'item' => 'Display', 'purchased_on' => '2026-10-01', 'quantity' => '1', 'unit_price' => '1550', 'vat_rate' => '8.10', 'journal_entry_id' => $linked->id,
        ], [['employee_id' => Employee::factory()->for($this->organization)->create()->id, 'share' => '100']]);
        // Already decided: costs on a job.
        $job = $this->makeProject('P26-01');
        $this->book('BAN-4', 'Material for the job', [
            new JournalLineData(accountId: (string) $this->software->id, debit: '80.00', credit: '0', allocations: [new LineAllocationData(projectId: $job->id, employeeId: null)]),
            $this->line($this->bank, '0', '80.00'),
        ]);
        // No purchase: a write-off, a salary.
        $writeOff = $this->makeAccount('6800', 'Write-offs', AccountType::Expense);
        $this->book('ABS-1', 'Write-off', [$this->line($writeOff, '500.00', '0'), $this->line($this->inventory, '0', '500.00')]);
        $salary = $this->makeAccount('5000', 'Salaries', AccountType::Expense);
        $this->book('LOHN-1', 'Salary', [$this->line($salary, '4000.00', '0'), $this->line($this->bank, '0', '4000.00')]);

        $receipts = collect($this->unassigned())->keyBy('reference');

        $this->assertSame(['BAN-2', 'WISE-1'], $receipts->keys()->sort()->values()->all());
        $this->assertSame($printer->id, $receipts['WISE-1']['journal_entry_id']);
        $this->assertSame('1000.00', $receipts['WISE-1']['net']);
        $this->assertSame('1081.00', $receipts['WISE-1']['gross']);
        $this->assertSame('8.10', $receipts['WISE-1']['vat_rate']);
        $this->assertSame(['1500'], $receipts['WISE-1']['accounts']);
        $this->assertSame($licence->id, $receipts['BAN-2']['journal_entry_id']);
    }

    public function test_receipts_set_aside_leave_the_list(): void
    {
        $licence = $this->purchase('BAN-2', 'Adobe licence', $this->software, '50.00', '0.00');
        $fee = $this->purchase('BAN-5', 'Card fee', $this->software, '2.00', '0.00');

        $this->actAsOrg()->get('/projects/acquisitions?year=2026')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('unassigned', 2));

        $this->actAsOrg()->post('/projects/acquisitions/receipts/skip', ['journal_entry_ids' => [$licence->id, $fee->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->unassigned());
    }

    public function test_a_receipt_for_a_job_is_assigned_from_the_list(): void
    {
        $this->purchase('WISE-1', 'Bambu Lab printer', $this->inventory, '1000.00', '81.00');
        $material = $this->purchase('BAN-2', 'Material for the fair', $this->software, '50.00', '0.00');
        $job = $this->makeProject('P26-01');

        $this->actAsOrg()->get('/projects/acquisitions?year=2026')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('projects')
                ->where('can.allocate', true)
                ->has('unassigned.0.cost_lines', 1)
                ->where('unassigned.1.cost_lines', []));
        $lines = $this->unassigned()[0]['cost_lines'];

        $this->actAsOrg()->post('/projects/unallocated', ['items' => array_map(fn ($id) => ['line_id' => $id, 'project_id' => $job->id], $lines)])
            ->assertSessionHasNoErrors();

        $this->assertSame(['WISE-1'], array_column($this->unassigned(), 'reference'), 'The printer still needs its acquisition.');
        $this->assertSame($job->id, (int) $material->lines()->whereHas('allocations')->sole()->allocations()->sole()->cost_center_id);
    }

    public function test_the_api_records_the_acquisition_or_the_decision_with_the_booking(): void
    {
        $anna = Employee::factory()->for($this->organization)->create(['first_name' => 'Anna', 'last_name' => 'A']);
        config(['features.api_access' => true]);
        $issued = $this->user->createToken('test', ['*']);
        $issued->accessToken->update(['organization_id' => $this->organization->id, 'type' => TokenType::Personal]);
        $token = $issued->plainTextToken;

        $entry = fn (string $reference, array $extra = []) => [
            'date' => '2026-10-16', 'reference' => $reference, 'description' => 'Bambu Lab', 'status' => 'posted',
            'lines' => [
                ['account_code' => '1500', 'debit' => '1000.00', 'credit' => '0.00'],
                ['account_code' => '1020', 'debit' => '0.00', 'credit' => '1000.00'],
            ],
            ...$extra,
        ];

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'a-1'])->postJson('/api/v1/journal-entries', $entry('WISE-1'))
            ->assertCreated()
            ->assertJsonPath('meta.acquisition_check', 'open');

        $created = $this->withToken($token)->withHeaders(['Idempotency-Key' => 'a-2'])->postJson('/api/v1/journal-entries', $entry('WISE-2', [
            'acquisitions' => [['item' => 'Bambu Lab H2D', 'supplier' => 'Bambu Lab', 'quantity' => 1, 'unit_price' => '1000.00', 'people' => [['employee_id' => $anna->id, 'share' => 100]]]],
        ]))->assertCreated()->assertJsonPath('meta.acquisition_check', 'recorded');

        $this->withToken($token)->withHeaders(['Idempotency-Key' => 'a-3'])->postJson('/api/v1/journal-entries', $entry('WISE-3', ['no_acquisition' => true]))
            ->assertCreated()
            ->assertJsonPath('meta.acquisition_check', 'none');

        $this->assertSame(['WISE-1'], array_column($this->unassigned(), 'reference'));
        $article = Acquisition::query()->sole();
        $this->assertSame($created->json('data.id'), $article->journal_entry_id);
        $this->assertSame('2026-10-16', $article->purchased_on->toDateString());
        $this->assertSame('1000.00', $article->net_amount);
    }

    /** @return list<array<string, mixed>> */
    private function unassigned(): array
    {
        return app(AcquisitionReceiptCheckService::class)->unassigned($this->organization->id, 2026);
    }

    private function purchase(string $reference, string $text, Account $account, string $net, string $vat): JournalEntry
    {
        return $this->book($reference, $text, array_values(array_filter([
            $this->line($account, $net, '0'),
            $vat !== '0.00' ? $this->line($this->vat, $vat, '0') : null,
            $this->line($this->bank, '0', bcadd($net, $vat, 2)),
        ])));
    }

    /** @param  list<JournalLineData>  $lines */
    private function book(string $reference, string $text, array $lines): JournalEntry
    {
        return app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData('2026-10-01', $reference, $text, $lines));
    }

    private function line(Account $account, string $debit, string $credit): JournalLineData
    {
        return new JournalLineData(accountId: (string) $account->id, debit: $debit, credit: $credit);
    }
}
