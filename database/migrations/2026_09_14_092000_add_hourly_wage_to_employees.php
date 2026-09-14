<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an employee be paid by the hour.
 *
 * Everything the module could calculate was a monthly salary pro-rated over the
 * days of the month. An hourly wage is the hours actually worked times a rate,
 * with holiday pay and the share of a thirteenth salary added as percentages —
 * how much of each is a matter of the employment contract, so both are stored
 * per employee rather than assumed.
 *
 * Existing employees are monthly, which is what they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('salary_type', 10)->default('monthly')->after('gross_salary');
            $table->decimal('hourly_rate', 15, 2)->nullable()->after('salary_type');
            $table->decimal('vacation_compensation_rate', 6, 4)->nullable()->after('hourly_rate');
            $table->decimal('thirteenth_compensation_rate', 6, 4)->nullable()->after('vacation_compensation_rate');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn([
                'salary_type',
                'hourly_rate',
                'vacation_compensation_rate',
                'thirteenth_compensation_rate',
            ]);
        });
    }
};
