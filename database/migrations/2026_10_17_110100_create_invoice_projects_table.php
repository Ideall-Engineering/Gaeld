<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 009, stage 2: which project an invoice belongs to. Kept beside the
 * invoice so that the project stays changeable after the invoice was sent
 * or archived, without touching the invoice record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained('cost_centers')->restrictOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'cost_center_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_projects');
    }
};
