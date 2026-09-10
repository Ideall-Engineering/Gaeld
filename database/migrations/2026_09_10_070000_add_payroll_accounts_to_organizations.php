<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an organization name the two payroll accounts that were hardcoded.
 *
 * Every deduction can already choose its account. The gross salary and the
 * expense reimbursement could not: they went to 5000 and 6530 whatever the
 * chart of accounts said. On a chart where 6530 means audit fees, a monthly
 * expense allowance was booked there without a word.
 *
 * Both columns are optional and fall back to the previous constants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('payroll_salary_account_code', 20)->nullable()->after('enabled_modules');
            $table->string('payroll_reimbursement_account_code', 20)->nullable()->after('payroll_salary_account_code');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['payroll_salary_account_code', 'payroll_reimbursement_account_code']);
        });
    }
};
