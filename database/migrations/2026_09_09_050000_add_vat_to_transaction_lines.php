<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->uuid('vat_rate_id')->nullable()->index();
            $table->decimal('vat_amount', 15, 2)->nullable();
            $table->string('vat_type', 20)->nullable();
            $table->string('vat_figure', 8)->nullable();

            $table->foreign('vat_rate_id')
                ->references('uuid')
                ->on('vat_rates')
                ->restrictOnDelete();
        });

        Schema::table('vat_entries', function (Blueprint $table): void {
            $table->string('figure', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->dropForeign(['vat_rate_id']);
            $table->dropColumn(['vat_rate_id', 'vat_amount', 'vat_type', 'vat_figure']);
        });

        Schema::table('vat_entries', function (Blueprint $table): void {
            $table->dropColumn('figure');
        });
    }
};
