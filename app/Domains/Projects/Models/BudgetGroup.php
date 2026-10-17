<?php

namespace App\Domains\Projects\Models;

use App\Domains\Accounting\Models\Account;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Projects\Enums\BudgetGroupKind;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A cost block of a year: several accounts planned and followed together,
 * such as "Software 6031 to 6035" (spec 009, stage 3).
 *
 * @property int $id
 * @property string $organization_id
 * @property int $year
 * @property string $name
 * @property BudgetGroupKind $kind
 * @property string|null $plan_amount
 * @property int $position
 * @property string|null $notes
 * @property-read Collection<int, Account> $accounts
 */
class BudgetGroup extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'year',
        'name',
        'kind',
        'plan_amount',
        'position',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'kind' => BudgetGroupKind::class,
            'plan_amount' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    /**
     * Who bears the block (carry_share) and who is credited for doing the
     * work (credit_share), in percent (spec 009, stage 5).
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'budget_group_shares')
            ->withPivot('carry_share', 'credit_share')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Account, $this> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'budget_group_accounts')->withTimestamps();
    }
}
