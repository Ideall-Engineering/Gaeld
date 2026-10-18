<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotes (offers) to customers. Kept apart from invoices on purpose: a quote
 * books nothing, is no receivable and has no reminders, so it must never show
 * up where invoices are summed or matched. An accepted quote becomes an
 * invoice draft (invoice_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->json('customer_snapshot')->nullable();
            $table->string('number', 50);
            $table->string('status', 20)->default('draft');
            $table->string('tax_treatment')->default('standard');
            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            $table->string('title')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->text('notes')->nullable();
            $table->string('payment_terms')->nullable();
            // The job (spec 009) the quote is for; carried to the invoice.
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'issue_date']);
        });

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('quote_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('item');
            $table->string('discount_type', 10)->nullable();
            $table->text('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->foreignId('vat_rate_id')->nullable()->constrained('vat_rates')->nullOnDelete();
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
    }
};
