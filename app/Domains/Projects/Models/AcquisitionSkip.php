<?php

namespace App\Domains\Projects\Models;

use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A purchase decided to be no acquisition: its costs are general costs.
 *
 * @property int $id
 * @property string $organization_id
 * @property string $journal_entry_id
 */
class AcquisitionSkip extends Model
{
    use Auditable, BelongsToOrganization;

    protected $fillable = ['organization_id', 'journal_entry_id'];
}
