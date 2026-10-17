<?php

namespace Tests\Feature\Projects;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\DTOs\LineAllocationData;
use App\Domains\Projects\Exceptions\InvalidAllocationException;
use App\Domains\Projects\Models\LineAllocation;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\LineAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Shape and ownership rules for line allocations (spec 009, User Story 2).
 */
class LineAllocationServiceTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Project $alpha;

    private Project $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->alpha = $this->makeProject('ALPHA');
        $this->beta = $this->makeProject('BETA');
    }

    public function test_a_split_with_a_person_is_stored(): void
    {
        $employee = Employee::factory()->for($this->organization)->create();

        $this->postWith([
            new LineAllocationData(projectId: $this->alpha->id, employeeId: null, share: '60'),
            new LineAllocationData(projectId: $this->beta->id, employeeId: $employee->id, share: '40'),
        ]);

        $this->assertSame(2, LineAllocation::count());
        $this->assertSame($employee->id, LineAllocation::query()->where('cost_center_id', $this->beta->id)->sole()->employee_id);
    }

    public function test_a_partial_allocation_is_allowed(): void
    {
        $this->postWith([new LineAllocationData(projectId: $this->alpha->id, employeeId: null, share: '50')]);

        $this->assertSame('50.0000', (string) LineAllocation::sole()->share);
    }

    /** @return array<string, array{0: array<int, array{0: string, 1: string}>}> */
    public static function invalidShares(): array
    {
        return [
            'over 100 in total' => [[['ALPHA', '60'], ['BETA', '41']]],
            'zero' => [[['ALPHA', '0']]],
            'negative' => [[['ALPHA', '-5']]],
            'over 100 alone' => [[['ALPHA', '100.5']]],
            'twice the same' => [[['ALPHA', '30'], ['ALPHA', '30']]],
        ];
    }

    /** @param  array<int, array{0: string, 1: string}>  $shares */
    #[DataProvider('invalidShares')]
    public function test_invalid_shares_are_refused(array $shares): void
    {
        $allocations = array_map(fn (array $share) => new LineAllocationData(
            projectId: $share[0] === 'ALPHA' ? $this->alpha->id : $this->beta->id,
            employeeId: null,
            share: $share[1],
        ), $shares);

        $this->expectException(InvalidAllocationException::class);
        $this->postWith($allocations);
    }

    public function test_an_allocation_without_project_or_person_is_refused(): void
    {
        $this->expectException(InvalidAllocationException::class);
        $this->postWith([new LineAllocationData(projectId: null, employeeId: null)]);
    }

    public function test_a_project_of_another_organization_is_refused(): void
    {
        $other = Organization::factory()->create();
        $foreign = Project::withoutGlobalScopes()->create([
            'organization_id' => $other->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
        ]);

        try {
            $this->postWith($this->whole($foreign));
            $this->fail('A foreign project must be refused.');
        } catch (InvalidAllocationException) {
            $this->assertSame(0, LineAllocation::count());
            $this->assertSame(0, JournalEntry::count(), 'The entry is rolled back with it.');
        }
    }

    public function test_a_person_of_another_organization_is_refused(): void
    {
        $other = Organization::factory()->create();
        $employee = Employee::factory()->for($other)->create();

        $this->expectException(InvalidAllocationException::class);
        $this->postWith([new LineAllocationData(projectId: null, employeeId: $employee->id)]);
    }

    public function test_the_shorthand_project_id_on_a_line_is_a_whole_line_share(): void
    {
        $line = JournalLineData::fromArray(['account_id' => '1', 'debit' => '1', 'credit' => '0', 'project_id' => $this->alpha->id]);

        $this->assertCount(1, $line->allocations);
        $this->assertSame('100', $line->allocations[0]->share);
        $this->assertNull(JournalLineData::fromArray(['account_id' => '1', 'debit' => '1', 'credit' => '0'])->allocations);
        $this->assertSame([], JournalLineData::fromArray(['account_id' => '1', 'debit' => '1', 'credit' => '0', 'project_id' => null])->allocations);
    }

    public function test_split_amounts_put_the_rounding_on_the_last_share(): void
    {
        $this->assertSame(['33.33', '33.33', '33.34'], LineAllocationService::splitAmount('100.00', ['33.3333', '33.3333', '33.3334']));
        $this->assertSame(['50.00'], LineAllocationService::splitAmount('100.00', ['50']));
        $this->assertSame(['0.33', '0.67'], LineAllocationService::splitAmount('1.00', ['33.3333', '66.6667']));
    }

    /** @param  array<int, LineAllocationData>  $allocations */
    private function postWith(array $allocations): void
    {
        app(LedgerService::class)->postEntry($this->organization->id, new JournalEntryData(
            date: '2026-08-10',
            reference: 'JE-1',
            description: 'Split',
            lines: [
                new JournalLineData(accountId: (string) $this->expense->id, debit: '100.00', credit: '0', allocations: $allocations),
                new JournalLineData(accountId: (string) $this->bank->id, debit: '0', credit: '100.00'),
            ],
        ));
    }
}
