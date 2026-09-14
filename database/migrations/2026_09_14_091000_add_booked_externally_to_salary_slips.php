<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a salary slip record a month that was booked by hand.
 *
 * A salary certificate sums the slips of a year, and only posted ones count.
 * An employer who ran part of the year outside the payroll module therefore
 * got a certificate covering part of the year, with no way to complete it:
 * posting those months again would book the salary a second time.
 *
 * A slip marked here calculates and documents but never posts. PostPayrollAction
 * refuses it outright rather than trusting the screen to hide the button —
 * booking a salary twice is the one mistake this is meant to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_slips', function (Blueprint $table): void {
            $table->boolean('booked_externally')->default(false)->after('posting_date');
        });
    }

    public function down(): void
    {
        Schema::table('salary_slips', function (Blueprint $table): void {
            $table->dropColumn('booked_externally');
        });
    }
};
