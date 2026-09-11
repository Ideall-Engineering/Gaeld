<?php

namespace Database\Factories\Domains\Payroll\Models;

use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'ahv_number' => '756.'.fake()->numerify('####.####.##'),
            'entry_date' => '2025-01-01',
            'gross_salary' => '6000.00',
            'is_active' => true,
        ];
    }

    /**
     * Everything the official salary certificate (Form 11) needs.
     *
     * The base factory leaves these empty on purpose, so tests that do not care
     * about the certificate still exercise the nullable columns.
     */
    public function withCertificateDetails(): self
    {
        return $this->state(fn (): array => [
            'date_of_birth' => '1985-04-12',
            'address' => fake()->streetAddress(),
            'postal_code' => (string) fake()->numberBetween(1000, 9999),
            'city' => fake()->city(),
            'place_of_origin' => fake()->city(),
            'job_title' => 'Sachbearbeiterin',
            'employment_rate' => '80.00',
        ]);
    }
}
