<?php

namespace App\Domains\Projects\Models;

use App\Domains\Accounting\Models\TransactionLine;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\AllocationSource;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Share of a transaction line that belongs to a project and/or a person.
 *
 * Lives beside the ledger and never writes to it, so it may change at any
 * time, also for entries in settled, closed or archived periods.
 *
 * @property int $id
 * @property string $organization_id
 * @property int $transaction_line_id
 * @property int|null $cost_center_id
 * @property string|null $employee_id
 * @property string $share
 * @property AllocationSource $source
 * @property-read TransactionLine $line
 * @property-read Project|null $project
 * @property-read Employee|null $employee
 */
class LineAllocation extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'transaction_line_id',
        'cost_center_id',
        'employee_id',
        'share',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'share' => 'decimal:4',
            'source' => AllocationSource::class,
        ];
    }

    /** @return BelongsTo<TransactionLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(TransactionLine::class, 'transaction_line_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'cost_center_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
