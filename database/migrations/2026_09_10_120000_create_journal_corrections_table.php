<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_corrections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->uuid('original_journal_entry_id');
            $table->uuid('reversal_journal_entry_id');
            $table->uuid('replacement_journal_entry_id');

            $table->string('status', 20)->default('draft');
            $table->text('reason');
            $table->string('source', 10);

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('client_operation_id')->nullable();
            $table->string('request_hash', 64)->nullable();

            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->foreign('original_journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
            $table->foreign('reversal_journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
            $table->foreign('replacement_journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();

            $table->unique('original_journal_entry_id');
            $table->unique('reversal_journal_entry_id');
            $table->unique('replacement_journal_entry_id');

            $table->index(['organization_id', 'original_journal_entry_id']);
            $table->index(['organization_id', 'status']);
            $table->unique(['organization_id', 'client_operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_corrections');
    }
};
