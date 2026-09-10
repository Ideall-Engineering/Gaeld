<?php

namespace Tests\Feature\Console;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The only way to configure payroll deduction rates — there is no screen for
 * them — so the command has to be strict about what it accepts.
 */
class DeductionRatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        foreach ([['2270', AccountType::Liability], ['2271', AccountType::Liability], ['5700', AccountType::Expense]] as [$code, $type]) {
            Account::create([
                'organization_id' => $this->organization->id,
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type->value,
            ]);
        }

        $this->employee = Employee::factory()->create([
            'organization_id' => $this->organization->id,
            'gross_salary' => '1107.69',
        ]);
    }

    public function test_it_creates_a_percentage_rate(): void
    {
        $this->artisan('gaeld:deduction-rates', [
            '--set' => true, '--code' => 'fak_employer', '--name' => 'FAK Zug',
            '--type' => 'employer', '--rate' => '1.45',
            '--account' => '2270', '--expense-account' => '5700',
        ])->assertSuccessful();

        $rate = DeductionRate::where('code', 'fak_employer')->sole();

        $this->assertSame('1.4500', (string) $rate->rate);
        $this->assertNull($rate->amount);
        $this->assertSame('2270', $rate->account_code);
        $this->assertSame('5700', $rate->expense_account_code);
        $this->assertNull($rate->employee_id);
        $this->assertTrue($rate->is_active);
    }

    public function test_it_creates_a_fixed_amount_for_a_single_employee(): void
    {
        $this->artisan('gaeld:deduction-rates', [
            '--set' => true, '--code' => 'lpp_employee', '--name' => 'BVG AN',
            '--type' => 'employee', '--amount' => '85.55',
            '--account' => '2271', '--employee' => $this->employee->id,
        ])->assertSuccessful();

        $rate = DeductionRate::where('code', 'lpp_employee')->sole();

        $this->assertSame('85.55', (string) $rate->amount);
        $this->assertNull($rate->rate);
        $this->assertSame($this->employee->id, $rate->employee_id);
    }

    public function test_setting_the_same_code_twice_updates_instead_of_duplicating(): void
    {
        $args = [
            '--set' => true, '--code' => 'avs_employee', '--type' => 'employee',
            '--rate' => '5.3', '--account' => '2270',
        ];

        $this->artisan('gaeld:deduction-rates', $args)->assertSuccessful();
        $this->artisan('gaeld:deduction-rates', [...$args, '--rate' => '5.4'])->assertSuccessful();

        $this->assertSame(1, DeductionRate::where('code', 'avs_employee')->count());
        $this->assertSame('5.4000', (string) DeductionRate::where('code', 'avs_employee')->sole()->rate);
    }

    public function test_an_employee_rate_lives_next_to_the_organization_wide_one(): void
    {
        $shared = ['--set' => true, '--code' => 'lpp_employee', '--type' => 'employee', '--account' => '2271'];

        $this->artisan('gaeld:deduction-rates', [...$shared, '--amount' => '19.35'])->assertSuccessful();
        $this->artisan('gaeld:deduction-rates', [...$shared, '--amount' => '85.55', '--employee' => $this->employee->id])
            ->assertSuccessful();

        $this->assertSame(2, DeductionRate::where('code', 'lpp_employee')->count());
    }

    public function test_giving_both_a_rate_and_an_amount_is_refused(): void
    {
        $this->artisan('gaeld:deduction-rates', [
            '--set' => true, '--code' => 'x', '--type' => 'employee',
            '--rate' => '1.0', '--amount' => '10.00',
        ])->assertFailed();

        $this->assertSame(0, DeductionRate::count());
    }

    public function test_giving_neither_a_rate_nor_an_amount_is_refused(): void
    {
        $this->artisan('gaeld:deduction-rates', [
            '--set' => true, '--code' => 'x', '--type' => 'employee',
        ])->assertFailed();

        $this->assertSame(0, DeductionRate::count());
    }

    public function test_an_unknown_account_is_refused(): void
    {
        $this->artisan('gaeld:deduction-rates', [
            '--set' => true, '--code' => 'x', '--type' => 'employee',
            '--rate' => '1.0', '--account' => '9999',
        ])->assertFailed();

        $this->assertSame(0, DeductionRate::count());
    }

    public function test_an_employee_of_another_organization_is_refused(): void
    {
        $foreign = Employee::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->artisan('gaeld:deduction-rates', [
            '--set' => true, '--code' => 'x', '--type' => 'employee',
            '--rate' => '1.0', '--account' => '2270', '--employee' => $foreign->id,
            '--organization' => $this->organization->id,
        ])->assertFailed();

        $this->assertSame(0, DeductionRate::count());
    }

    public function test_it_deletes_a_rate(): void
    {
        $this->artisan('gaeld:deduction-rates', [
            '--set' => true, '--code' => 'ktg_employee', '--type' => 'employee',
            '--rate' => '0.7785', '--account' => '2270',
        ])->assertSuccessful();

        $this->artisan('gaeld:deduction-rates', ['--delete' => true, '--code' => 'ktg_employee'])
            ->assertSuccessful();

        $this->assertSame(0, DeductionRate::count());
    }

    public function test_listing_warns_when_nothing_is_configured(): void
    {
        $this->artisan('gaeld:deduction-rates')
            ->expectsOutputToContain('No deduction rates configured')
            ->assertSuccessful();
    }
}
