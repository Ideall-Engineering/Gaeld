<?php

namespace App\Console\Commands;

use App\Domains\Accounting\Models\Account;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Payroll\Models\DeductionRate;
use App\Domains\Payroll\Models\Employee;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Shows and configures the payroll deduction rates of an organization.
 *
 * There is no screen for these rates, and the accounts they book to differ from
 * one chart of accounts to the next — so this command makes no assumptions and
 * asks for every field explicitly.
 *
 * A rate carries either a percentage of the gross salary or a fixed amount per
 * period; a pension contribution from a contract is the latter. Giving an
 * employee makes the rate apply to that person only and override the
 * organization-wide rate of the same code.
 */
class DeductionRatesCommand extends Command
{
    protected $signature = 'gaeld:deduction-rates
                            {--organization= : Organization id (default: the only one, if there is exactly one)}
                            {--set : Create or update a rate}
                            {--set-accounts : Set the payroll salary and reimbursement accounts, and the payday}
                            {--salary-account= : Account the gross salary is debited to}
                            {--reimbursement-account= : Account an expense reimbursement is debited to}
                            {--payday= : Day of the month a payroll run is posted on, 1–31}
                            {--delete : Remove a rate}
                            {--code= : Deduction code, e.g. avs_employee or fak_employer}
                            {--name= : Human-readable name}
                            {--type= : employee or employer}
                            {--rate= : Percentage of the gross salary, e.g. 5.3}
                            {--amount= : Fixed amount per period, e.g. 19.35}
                            {--account= : Liability account code the deduction is credited to}
                            {--expense-account= : Expense account for the employer share}
                            {--employee= : Employee id — makes the rate apply to that person only}
                            {--inactive : Store the rate but leave it switched off}';

    protected $description = 'Show or configure payroll deduction rates';

    public function handle(): int
    {
        $organization = $this->resolveOrganization();

        if ($organization === null) {
            return self::FAILURE;
        }

        if ($this->option('set-accounts')) {
            return $this->setAccounts($organization);
        }

        if ($this->option('set')) {
            return $this->set($organization);
        }

        if ($this->option('delete')) {
            return $this->delete($organization);
        }

        return $this->list($organization);
    }

    private function list(Organization $organization): int
    {
        $rates = DeductionRate::query()
            ->where('organization_id', $organization->id)
            ->orderBy('type')
            ->orderBy('code')
            ->get();

        if ($rates->isEmpty()) {
            $this->warn('No deduction rates configured. The payroll module falls back to its built-in');
            $this->warn('defaults for AVS, AC, AANP and LPP, which book to 2270, 2271 and 2272.');
            $this->newLine();
            $this->line('Gross salary → '.($organization->payroll_salary_account_code ?? '5000 (default)')
                .'   ·   Reimbursement → '.($organization->payroll_reimbursement_account_code ?? '6530 (default)'));

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Name', 'Type', 'Rate', 'Amount', 'Liability', 'Expense', 'Employee', 'Active'],
            $rates->map(fn (DeductionRate $rate): array => [
                $rate->code,
                $rate->name,
                $rate->type,
                $rate->rate === null ? '—' : rtrim(rtrim((string) $rate->rate, '0'), '.').' %',
                $rate->amount === null ? '—' : (string) $rate->amount,
                $rate->account_code ?? '(default)',
                $rate->expense_account_code ?? ($rate->type === 'employer' ? '(5700)' : '—'),
                $rate->employee_id === null ? 'all' : substr($rate->employee_id, 0, 8),
                $rate->is_active ? 'yes' : 'no',
            ])->all(),
        );

        $this->newLine();
        $this->line('Gross salary → '.($organization->payroll_salary_account_code ?? '5000 (default)')
            .'   ·   Reimbursement → '.($organization->payroll_reimbursement_account_code ?? '6530 (default)')
            .'   ·   Payday → '.($organization->payroll_payday ?? 'last day of the month'));
        $this->newLine();
        $this->line('A rate without a liability account uses the built-in mapping. A code that has');
        $this->line('neither is refused when the payroll entry is posted, never booked silently.');

        return self::SUCCESS;
    }

    private function setAccounts(Organization $organization): int
    {
        $attributes = [];

        foreach ([
            'salary-account' => 'payroll_salary_account_code',
            'reimbursement-account' => 'payroll_reimbursement_account_code',
        ] as $option => $column) {
            $code = $this->option($option);

            if ($code === null) {
                continue;
            }

            if (! $this->accountExists($organization, (string) $code)) {
                $this->error("Account {$code} does not exist in this organization (--{$option}).");

                return self::FAILURE;
            }

            $attributes[$column] = (string) $code;
        }

        $payday = $this->option('payday');

        if ($payday !== null) {
            if (! ctype_digit((string) $payday) || (int) $payday < 1 || (int) $payday > 31) {
                $this->error('--payday must be a day of the month between 1 and 31.');

                return self::FAILURE;
            }

            $attributes['payroll_payday'] = (int) $payday;
        }

        if ($attributes === []) {
            $this->error('Give --salary-account, --reimbursement-account and/or --payday.');

            return self::FAILURE;
        }

        $organization->update($attributes);
        $this->info('Payroll settings updated.');

        return self::SUCCESS;
    }

    private function set(Organization $organization): int
    {
        $code = (string) $this->option('code');
        $type = (string) $this->option('type');
        $rate = $this->option('rate');
        $amount = $this->option('amount');

        if ($code === '') {
            $this->error('--code is required.');

            return self::FAILURE;
        }

        if (! in_array($type, ['employee', 'employer'], true)) {
            $this->error('--type must be employee or employer.');

            return self::FAILURE;
        }

        if (($rate === null) === ($amount === null)) {
            $this->error('Give exactly one of --rate and --amount: a rate is either a percentage or a fixed amount.');

            return self::FAILURE;
        }

        foreach (['account' => $this->option('account'), 'expense-account' => $this->option('expense-account')] as $option => $accountCode) {
            if ($accountCode !== null && ! $this->accountExists($organization, (string) $accountCode)) {
                $this->error("Account {$accountCode} does not exist in this organization (--{$option}).");

                return self::FAILURE;
            }
        }

        $employeeId = $this->option('employee');

        if ($employeeId !== null && ! Employee::query()
            ->where('organization_id', $organization->id)
            ->whereKey($employeeId)
            ->exists()) {
            $this->error("Employee {$employeeId} does not belong to this organization.");

            return self::FAILURE;
        }

        $existing = DeductionRate::query()
            ->where('organization_id', $organization->id)
            ->where('code', $code)
            ->where(fn ($query) => $employeeId === null
                ? $query->whereNull('employee_id')
                : $query->where('employee_id', $employeeId))
            ->first();

        $attributes = [
            'name' => (string) ($this->option('name') ?? $existing?->name ?? $code),
            'type' => $type,
            'rate' => $rate === null ? null : (string) $rate,
            'amount' => $amount === null ? null : Money::normalize((string) $amount),
            'account_code' => $this->option('account'),
            'expense_account_code' => $this->option('expense-account'),
            'is_active' => ! $this->option('inactive'),
        ];

        if ($existing !== null) {
            $existing->update($attributes);
            $this->info("Updated {$code}".($employeeId === null ? '' : " for employee {$employeeId}").'.');

            return self::SUCCESS;
        }

        DeductionRate::create([
            'organization_id' => $organization->id,
            'employee_id' => $employeeId,
            'code' => $code,
            ...$attributes,
        ]);

        $this->info("Created {$code}".($employeeId === null ? '' : " for employee {$employeeId}").'.');

        return self::SUCCESS;
    }

    private function delete(Organization $organization): int
    {
        $code = (string) $this->option('code');

        if ($code === '') {
            $this->error('--code is required.');

            return self::FAILURE;
        }

        $employeeId = $this->option('employee');

        $deleted = DeductionRate::query()
            ->where('organization_id', $organization->id)
            ->where('code', $code)
            ->where(fn ($query) => $employeeId === null
                ? $query->whereNull('employee_id')
                : $query->where('employee_id', $employeeId))
            ->delete();

        if ($deleted === 0) {
            $this->warn("No rate {$code} found.");

            return self::FAILURE;
        }

        $this->info("Removed {$code}.");

        return self::SUCCESS;
    }

    private function accountExists(Organization $organization, string $code): bool
    {
        return Account::query()
            ->where('organization_id', $organization->id)
            ->where('code', $code)
            ->exists();
    }

    private function resolveOrganization(): ?Organization
    {
        $id = $this->option('organization');

        if ($id !== null) {
            $organization = Organization::find($id);

            if ($organization === null) {
                $this->error("Organization {$id} not found.");
            }

            return $organization;
        }

        $organizations = Organization::query()->limit(2)->get();

        if ($organizations->count() === 1) {
            return $organizations->first();
        }

        $this->error('Specify --organization: this installation has '.($organizations->isEmpty() ? 'none' : 'more than one').'.');

        return null;
    }
}
