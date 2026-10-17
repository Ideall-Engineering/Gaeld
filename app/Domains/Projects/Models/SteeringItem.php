<?php

namespace App\Domains\Projects\Models;

use App\Domains\Payroll\Models\Employee;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned drawing of a person in a year, such as salary, the 13th,
 * employer contributions, an allowance or a bonus (spec 009, stage 5).
 *
 * @property int $id
 * @property string $organization_id
 * @property int $year
 * @property string $employee_id
 * @property string $label
 * @property string $amount
 * @property int $position
 * @property-read Employee $employee
 */
class SteeringItem extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = ['organization_id', 'year', 'employee_id', 'label', 'amount', 'position'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'amount' => 'decimal:2', 'position' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
