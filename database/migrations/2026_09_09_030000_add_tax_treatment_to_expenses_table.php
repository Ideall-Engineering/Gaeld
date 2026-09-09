<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives expenses the input-side counterpart of invoices.tax_treatment.
 *
 * Until now an expense could only say how much VAT it carried, never where that
 * VAT came from. Acquisition tax on foreign services and import VAT on goods
 * had no place to go, so both were indistinguishable from ordinary Swiss input
 * tax. 'standard' preserves the behaviour of every existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('tax_treatment')->default('standard')->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('tax_treatment');
        });
    }
};
