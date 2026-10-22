<?php

namespace App\Domains\Projects\Models;

use App\Domains\Payroll\Models\Employee;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person pays another for work done for everyone, such as the
 * bookkeeping, optionally for a job (2026-10-22). Counts in the steering
 * only: the receiver gains the amount, the payer gives it up.
 *
 * @property int $id
 * @property string $organization_id
 * @property int $year
 * @property string $label
 * @property string $from_employee_id
 * @property string $to_employee_id
 * @property int|null $cost_center_id
 * @property string $amount
 * @property-read Employee $from
 * @property-read Employee $to
 * @property-read Project|null $project
 */
class InternalSettlement extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = ['organization_id', 'year', 'label', 'from_employee_id', 'to_employee_id', 'cost_center_id', 'amount'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_employee_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_employee_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'cost_center_id');
    }
}
