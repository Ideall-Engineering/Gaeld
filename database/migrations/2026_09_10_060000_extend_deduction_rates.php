<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a deduction rate name its own accounts, carry a fixed amount instead of
 * a percentage, and belong to a single employee.
 *
 * Without this, only AVS, AC, LPP and withholding tax can be booked: every
 * other contribution — family allowance, administration costs, accident and
 * daily-sickness insurance — lands in the employer total without a matching
 * credit, and the payroll entry no longer balances.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deduction_rates', function (Blueprint $table): void {
            $table->string('account_code', 20)->nullable()->after('type');
            $table->string('expense_account_code', 20)->nullable()->after('account_code');
            $table->decimal('amount', 15, 2)->nullable()->after('rate');
            $table->foreignUuid('employee_id')->nullable()->after('organization_id')
                ->constrained('employees')->cascadeOnDelete();
        });

        // A rate now carries either a percentage or a fixed amount.
        DB::statement('ALTER TABLE deduction_rates ALTER COLUMN rate DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE deduction_rates SET rate = '0.0000' WHERE rate IS NULL");
        DB::statement('ALTER TABLE deduction_rates ALTER COLUMN rate SET NOT NULL');

        Schema::table('deduction_rates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
            $table->dropColumn(['account_code', 'expense_account_code', 'amount']);
        });
    }
};
