<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per suggestion a rule made, and what the human did with it.
 *
 * This is the audit trail the pilot rests on: it records what was proposed
 * before anyone touched it, who decided, and what they changed it to. Without
 * the "what it was corrected to" half there is no way to tell a rule that is
 * quietly wrong from one that is right, and no basis for ever promoting a rule
 * from suggest to auto_apply.
 *
 * The unique index on the transaction makes re-running the engine over an
 * already-processed statement idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_rule_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('bank_rule_id')->nullable();
            $table->unsignedBigInteger('bank_transaction_id');

            // The proposal, frozen at the moment it was made. Kept verbatim so a
            // later edit to the rule cannot rewrite history.
            $table->string('matched_text')->nullable();
            $table->string('suggested_account_code');
            $table->string('suggested_tax_treatment');
            $table->unsignedBigInteger('suggested_vat_rate_id')->nullable();
            $table->string('reason')->nullable();
            $table->string('action');                       // suggest | auto_apply
            $table->unsignedSmallInteger('confidence')->default(0);

            // The decision.
            $table->string('outcome')->default('pending');  // pending | confirmed | corrected | rejected
            $table->string('final_account_code')->nullable();
            $table->string('final_tax_treatment')->nullable();
            $table->unsignedBigInteger('final_vat_rate_id')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('bank_rule_id')->references('id')->on('bank_rules')->nullOnDelete();
            $table->foreign('bank_transaction_id')->references('id')->on('bank_transactions')->cascadeOnDelete();
            $table->foreign('suggested_vat_rate_id')->references('id')->on('vat_rates')->nullOnDelete();
            $table->foreign('final_vat_rate_id')->references('id')->on('vat_rates')->nullOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();

            $table->unique('bank_transaction_id');
            $table->index(['organization_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_rule_applications');
    }
};
