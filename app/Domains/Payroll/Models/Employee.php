<?php

namespace App\Domains\Payroll\Models;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Users\Models\User;
use App\Support\Traits\Auditable;
use App\Support\Traits\BelongsToOrganization;
use Database\Factories\Domains\Payroll\Models\EmployeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Employee record within an organization's payroll module.
 *
 * Tracks AHV number, salary, entry/exit dates, and source-tax liability.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $first_name
 * @property string $last_name
 * @property Carbon|null $date_of_birth
 * @property string|null $email
 * @property string|null $address
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $place_of_origin
 * @property string|null $job_title
 * @property string|null $employment_rate
 * @property string|null $ahv_number
 * @property Carbon $entry_date
 * @property Carbon|null $exit_date
 * @property string $gross_salary
 * @property string $salary_type
 * @property string|null $hourly_rate
 * @property string|null $vacation_compensation_rate
 * @property string|null $thirteenth_compensation_rate
 * @property string|null $expense_allowance
 * @property bool $is_active
 * @property bool $is_source_tax_subject
 * @property bool $has_thirteenth_salary
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use Auditable, BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    protected $appends = ['status'];

    // ahv_number is hidden from array/JSON serialization; access explicitly only.
    protected $hidden = ['ahv_number'];

    protected $attributes = [
        'has_thirteenth_salary' => false,
    ];

    protected $fillable = [
        'organization_id',
        'user_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'email',
        'address',
        'postal_code',
        'city',
        'place_of_origin',
        'job_title',
        'employment_rate',
        'iban',
        'ahv_number',
        'entry_date',
        'exit_date',
        'gross_salary',
        'salary_type',
        'hourly_rate',
        'vacation_compensation_rate',
        'thirteenth_compensation_rate',
        'expense_allowance',
        'is_active',
        'is_source_tax_subject',
        'has_thirteenth_salary',
        'source_tax_canton',
        'source_tax_tariff',
        'source_tax_municipality_code',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'entry_date' => 'date',
            'exit_date' => 'date',
            'gross_salary' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'vacation_compensation_rate' => 'decimal:4',
            'thirteenth_compensation_rate' => 'decimal:4',
            'expense_allowance' => 'decimal:2',
            'employment_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'is_source_tax_subject' => 'boolean',
            'has_thirteenth_salary' => 'boolean',
            // ahv_number is encrypted at rest using Laravel's Encrypter (APP_KEY).
            // Stored ciphertext is never interpretable without the application key.
            'ahv_number' => 'encrypted',
            // IBAN is encrypted at rest — same rationale as ahv_number.
            'iban' => 'encrypted',
        ];
    }

    /**
     * Paid for the hours worked rather than a monthly salary.
     *
     * The distinction runs through the whole calculation: an hourly wage is not
     * pro-rated over the days of a month, carries no December thirteenth month,
     * and needs the month's hours before it can be worked out at all.
     */
    public function isHourly(): bool
    {
        return $this->salary_type === 'hourly';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SalarySlip, $this> */
    public function salarySlips(): HasMany
    {
        return $this->hasMany(SalarySlip::class);
    }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getStatusAttribute(): string
    {
        return $this->is_active ? 'active' : 'inactive';
    }

    /**
     * Header fields the official salary certificate (Form 11) cannot omit.
     *
     * Place of origin, job title and employment rate are deliberately absent:
     * they are not part of the form and only enrich the remarks box.
     *
     * @return array<int, string>
     */
    public function missingCertificateFields(): array
    {
        $required = [
            'date_of_birth' => $this->date_of_birth,
            'address' => $this->address,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'ahv_number' => $this->ahv_number,
        ];

        return array_keys(array_filter(
            $required,
            fn ($value): bool => $value === null || $value === '',
        ));
    }

    /**
     * The same list, translated for display.
     *
     * The keys are spelled out rather than interpolated so the translation
     * checker can see them.
     *
     * @return array<int, string>
     */
    public function missingCertificateFieldLabels(): array
    {
        $labels = [
            'date_of_birth' => __('validation.attributes.date_of_birth'),
            'address' => __('validation.attributes.address'),
            'postal_code' => __('validation.attributes.postal_code'),
            'city' => __('validation.attributes.city'),
            'ahv_number' => __('validation.attributes.ahv_number'),
        ];

        return array_values(array_intersect_key($labels, array_flip($this->missingCertificateFields())));
    }
}
