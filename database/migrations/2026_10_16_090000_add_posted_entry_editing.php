<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an organization amend or delete posted journal entries while their
 * period is still open (spec 008). Every change keeps the full prior state
 * in `journal_entry_revisions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('posted_entry_edit_mode', 16)->default('strict');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });

        Schema::create('journal_entry_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            // Deliberately no foreign key: a revision must outlive the
            // deletion of the entry it describes.
            $table->uuid('journal_entry_id');
            $table->unsignedInteger('revision');
            $table->string('kind', 16);
            $table->string('reference', 100)->nullable();
            $table->jsonb('snapshot');
            $table->text('reason')->nullable();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->string('source', 10);
            $table->string('idempotency_key')->nullable();

            $table->timestamp('created_at');

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->unique(['journal_entry_id', 'revision']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'kind', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_revisions');

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn('revision');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('posted_entry_edit_mode');
        });
    }
};
