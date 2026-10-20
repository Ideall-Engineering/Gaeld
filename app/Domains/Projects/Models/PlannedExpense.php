<?php

namespace App\Domains\Projects\Models;

use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single expense known ahead that no other record holds yet (spec 012,
 * stage 3): expected on its date until it is marked done.
 *
 * @property int $id
 * @property string $organization_id
 * @property Carbon $date
 * @property string $label
 * @property string $amount
 * @property string $vat_amount
 * @property string|null $account_code
 * @property Carbon|null $done_at
 */
class PlannedExpense extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = ['organization_id', 'date', 'label', 'amount', 'vat_amount', 'account_code', 'done_at'];

    protected function casts(): array
    {
        return ['date' => 'date', 'amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'done_at' => 'datetime'];
    }
}
