<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a payroll run say when it was paid.
 *
 * The journal entry was dated the last day of the payroll month, whatever the
 * bank statement said. An employer paying on the 26th got an entry four days
 * after the money left, and no way to correct it short of unposting.
 *
 * A slip carries its own date; an organization can name the day of the month
 * its payroll usually lands on, which is what a run offers by default. Both
 * are optional and fall back to the last day of the month, so an installation
 * that sets neither keeps exactly what it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_slips', function (Blueprint $table): void {
            $table->date('posting_date')->nullable()->after('period_year');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('payroll_payday')->nullable()->after('payroll_reimbursement_account_code');
        });
    }

    public function down(): void
    {
        Schema::table('salary_slips', function (Blueprint $table): void {
            $table->dropColumn('posting_date');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('payroll_payday');
        });
    }
};
