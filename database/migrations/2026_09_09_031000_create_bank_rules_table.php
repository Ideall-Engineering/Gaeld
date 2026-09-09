<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable posting rules for imported bank transactions.
 *
 * The three hard-coded classes under Domains/Banking/Rules cannot express what
 * an accountant actually needs to say — which supplier, which account, which VAT
 * treatment, and whether a match may post by itself. This table does, one row
 * per rule, owned by the organization.
 *
 * The default for `action` is deliberately 'suggest': a new rule never writes to
 * the ledger until somebody promotes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->string('name');

            // What to look for, and in which part of the transaction.
            $table->string('match_text');
            $table->string('match_field')->default('any');   // any | description | counterparty | reference
            $table->string('direction')->default('debit');   // debit | credit | any

            // What to book it as.
            $table->string('account_code');
            $table->string('tax_treatment')->default('standard');
            $table->unsignedBigInteger('vat_rate_id')->nullable();

            // How it behaves.
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('action')->default('suggest');    // suggest | auto_apply
            $table->boolean('is_active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();

            // Shown to the user next to the suggestion, so a proposal can be
            // judged without opening the rule.
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('vat_rate_id')->references('id')->on('vat_rates')->nullOnDelete();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_rules');
    }
};
