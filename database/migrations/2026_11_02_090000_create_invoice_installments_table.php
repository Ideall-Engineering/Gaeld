<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 013: the rates of an invoice paid by payment plan. Payments are not
 * tied to a rate; they fill the rates in order of their due dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('qr_reference', 27)->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'position']);
            $table->unique(['organization_id', 'qr_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_installments');
    }
};
