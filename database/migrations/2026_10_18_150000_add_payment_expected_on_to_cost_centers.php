<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day a job's payment is expected; it places the expected amount in
 * its year ahead of billing month and target date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->date('payment_expected_on')->nullable()->after('billing_month');
        });
    }

    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->dropColumn('payment_expected_on');
        });
    }
};
