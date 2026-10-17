<?php

namespace App\Domains\Projects\Models;

use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Contacts\Models\Contact;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\ProjectKind;
use App\Domains\Projects\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cost centre seen as a project (spec 009).
 *
 * Shares the `cost_centers` table with {@see CostCenter}, so the existing
 * analytical module keeps working on the same records.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property int|null $parent_id
 * @property bool $is_active
 * @property ProjectKind $kind
 * @property ProjectStatus $status
 * @property int|null $contact_id
 * @property string|null $responsible_employee_id
 * @property Carbon|null $target_date
 * @property string|null $expected_amount
 * @property string|null $notes
 * @property-read Project|null $parent
 * @property-read Contact|null $contact
 * @property-read Employee|null $responsible
 */
class Project extends CostCenter
{
    protected $table = 'cost_centers';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'parent_id',
        'is_active',
        'kind',
        'status',
        'contact_id',
        'responsible_employee_id',
        'target_date',
        'expected_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'kind' => ProjectKind::class,
            'status' => ProjectStatus::class,
            'target_date' => 'date',
            'expected_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    /** @return HasMany<LineAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(LineAllocation::class, 'cost_center_id');
    }

    /**
     * Ids of this project and every project below it, at any depth.
     *
     * @return array<int, int>
     */
    public function selfAndDescendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];

        // The parent rule forbids cycles; the depth cap only guards against
        // data written around it.
        for ($depth = 0; $frontier !== [] && $depth < 32; $depth++) {
            $frontier = self::query()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->diff($ids)
                ->values()
                ->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
