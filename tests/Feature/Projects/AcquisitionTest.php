<?php

namespace Tests\Feature\Projects;

use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\AcquisitionStatus;
use App\Domains\Projects\Models\Acquisition;
use App\Domains\Projects\Services\AcquisitionService;
use App\Domains\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;
use Tests\Traits\BuildsPostedJournalEntries;
use Tests\Traits\BuildsProjects;
use Tests\Traits\WithAuthenticatedOrganization;

/**
 * Material and devices assigned to people (spec 009, User Story 11).
 */
class AcquisitionTest extends TestCase
{
    use BuildsPostedJournalEntries, BuildsProjects, RefreshDatabase, WithAuthenticatedOrganization;

    private Employee $anna;

    private Employee $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
        $this->setUpPostedEntryFixtures();
        $this->enableProjects();
        $this->anna = Employee::factory()->for($this->organization)->create(['first_name' => 'Anna', 'last_name' => 'A']);
        $this->ben = Employee::factory()->for($this->organization)->create(['first_name' => 'Ben', 'last_name' => 'B']);
    }

    public function test_the_net_amount_takes_off_the_input_vat(): void
    {
        $this->assertSame('1433.86', AcquisitionService::netFor('1550.00', '8.10'));
        $this->assertSame('1607.74', AcquisitionService::netFor('1607.74', null));
    }

    public function test_an_article_for_both_charges_each_half(): void
    {
        $article = $this->buy('Carpet cleaner', '3', '11.90', [[$this->anna, '50'], [$this->ben, '50']]);

        $this->assertSame('35.70', $article->gross_amount);
        $this->assertSame('33.02', $article->net_amount);
        $this->assertSame([$this->anna->id => '16.51', $this->ben->id => '16.51'], AcquisitionService::charges($article));
    }

    public function test_a_returned_article_charges_nobody(): void
    {
        $this->buy('Display', '1', '1550', [[$this->anna, '100']]);
        $this->buy('Returned display', '1', '1550', [[$this->anna, '100']], status: AcquisitionStatus::Returned);

        $charges = app(AcquisitionService::class)->chargesByPerson($this->organization->id, '2026-01-01', '2026-12-31');

        $this->assertSame([$this->anna->id => '1433.86'], $charges);
    }

    public function test_return_deadlines_within_fourteen_days_are_flagged(): void
    {
        $this->buy('Due soon', '1', '10', [[$this->anna, '100']], returnUntil: '2026-10-25');
        $this->buy('Due later', '1', '10', [[$this->anna, '100']], returnUntil: '2026-11-30');
        $this->buy('Returned', '1', '10', [[$this->anna, '100']], returnUntil: '2026-10-20', status: AcquisitionStatus::Returned);
        $this->buy('Past', '1', '10', [[$this->anna, '100']], returnUntil: '2026-10-01');

        $rows = collect(app(AcquisitionService::class)->overview($this->organization->id, 2026, today: Carbon::parse('2026-10-17'))['rows'])->keyBy('item');

        $this->assertTrue($rows['Due soon']['return_due']);
        $this->assertFalse($rows['Due later']['return_due']);
        $this->assertFalse($rows['Returned']['return_due']);
        $this->assertFalse($rows['Past']['return_due']);
    }

    public function test_articles_are_managed_on_the_page(): void
    {
        $entry = $this->postSimpleEntry();

        $this->actAsOrg()->post('/projects/acquisitions', [
            'item' => 'Studio Display', 'category' => 'Hardware', 'supplier' => 'Shop', 'purchased_on' => '2026-09-28',
            'quantity' => 1, 'unit_price' => '1550', 'vat_rate' => '8.10', 'status' => 'delivered',
            'return_until' => '2026-10-28', 'warranty_until' => '2028-09-28', 'journal_entry_id' => $entry->id,
            'people' => [['employee_id' => $this->anna->id, 'share' => 100]],
        ])->assertSessionHasNoErrors();

        $article = Acquisition::sole();
        $this->assertSame('1433.86', $article->net_amount);
        $this->assertSame($entry->id, $article->journal_entry_id);

        $this->actAsOrg()->put("/projects/acquisitions/{$article->id}", [
            'item' => 'Studio Display', 'purchased_on' => '2026-09-28', 'quantity' => 1, 'unit_price' => '1550',
            'vat_rate' => '8.10', 'status' => 'returned',
            'people' => [['employee_id' => $this->anna->id, 'share' => 50], ['employee_id' => $this->ben->id, 'share' => 50]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(AcquisitionStatus::Returned, $article->fresh()->status);

        $this->actAsOrg()->get('/projects/acquisitions?year=2026')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Acquisitions')
                ->where('overview.rows.0.item', 'Studio Display')
                ->where('overview.people', []));
        $this->assertStringContainsString('Studio Display', $this->actAsOrg()->get('/projects/acquisitions/export?year=2026')->streamedContent());

        $this->actAsOrg()->delete("/projects/acquisitions/{$article->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Acquisition::count());
    }

    public function test_shares_must_add_up_to_a_hundred(): void
    {
        $this->actAsOrg()->post('/projects/acquisitions', [
            'item' => 'X', 'purchased_on' => '2026-09-28', 'quantity' => 1, 'unit_price' => '10', 'status' => 'delivered',
            'people' => [['employee_id' => $this->anna->id, 'share' => 60], ['employee_id' => $this->ben->id, 'share' => 30]],
        ])->assertSessionHasErrors('people');
    }

    public function test_a_viewer_cannot_add_articles(): void
    {
        $viewer = User::factory()->create(['onboarding_completed_at' => now()]);
        $this->organization->users()->attach($viewer->id, ['role' => 'viewer']);
        $this->assignOrganizationRole($viewer, $this->organization, 'viewer');

        $this->actingAs($viewer)->withSession(['current_organization_id' => $this->organization->id])
            ->post('/projects/acquisitions', ['item' => 'X'])
            ->assertForbidden();
    }

    /** @param  array<int, array{0: Employee, 1: string}>  $people */
    private function buy(string $item, string $quantity, string $price, array $people, ?string $returnUntil = null, AcquisitionStatus $status = AcquisitionStatus::Delivered): Acquisition
    {
        return app(AcquisitionService::class)->save(null, $this->organization->id, [
            'item' => $item,
            'purchased_on' => '2026-09-28',
            'quantity' => $quantity,
            'unit_price' => $price,
            'vat_rate' => '8.10',
            'status' => $status->value,
            'return_until' => $returnUntil,
        ], array_map(fn (array $p) => ['employee_id' => $p[0]->id, 'share' => $p[1]], $people));
    }
}
