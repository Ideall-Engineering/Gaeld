<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 009: entries whose allocations could not be carried over unambiguously
 * after their lines were rebuilt. Gäld never guesses; a person decides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('journal_entry_id');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->string('reason', 40);
            $table->json('previous');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'resolved_at']);
        });

        DB::statement('CREATE UNIQUE INDEX allocation_reviews_open_entry_unique ON allocation_reviews (journal_entry_id) WHERE resolved_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('allocation_reviews');
    }
};
