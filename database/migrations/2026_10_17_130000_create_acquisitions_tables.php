<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 009, stage 4: material and devices assigned to people. One row per
 * article, since one order often holds articles for different people; the
 * ledger is not written, an optional link to the entry serves matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('item');
            $table->string('category', 60)->nullable();
            $table->string('supplier', 120)->nullable();
            $table->date('purchased_on');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('net_amount', 15, 2);
            $table->string('status', 20)->default('delivered');
            $table->date('return_until')->nullable();
            $table->date('warranty_until')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'purchased_on']);
        });

        Schema::create('acquisition_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acquisition_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->decimal('share', 7, 4);
            $table->timestamps();

            $table->unique(['acquisition_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_people');
        Schema::dropIfExists('acquisitions');
    }
};
