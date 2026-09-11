<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personal details a Swiss salary certificate needs.
 *
 * Date of birth and the postal address are header fields on the official
 * Lohnausweis (Form 11). Place of origin, job title and employment rate are
 * not on the form itself — they are carried for the remarks box (Ziff. 15),
 * the employee detail screen and cantonal payroll declarations.
 *
 * expense_allowance is the monthly flat expense sum agreed with an employee.
 * It becomes the default reimbursement of a payroll run, and reaches the
 * certificate as flat expenses (Ziff. 13.2.3).
 *
 * All columns are nullable: existing employee records stay valid, and the
 * certificate itself refuses to render while the fields it needs are empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('last_name');
            $table->text('address')->nullable()->after('email');
            $table->string('postal_code', 10)->nullable()->after('address');
            $table->string('city')->nullable()->after('postal_code');
            $table->string('place_of_origin')->nullable()->after('city');
            $table->string('job_title')->nullable()->after('place_of_origin');
            $table->decimal('employment_rate', 5, 2)->nullable()->after('job_title');
            $table->decimal('expense_allowance', 15, 2)->nullable()->after('gross_salary');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_birth',
                'address',
                'postal_code',
                'city',
                'place_of_origin',
                'job_title',
                'employment_rate',
                'expense_allowance',
            ]);
        });
    }
};
