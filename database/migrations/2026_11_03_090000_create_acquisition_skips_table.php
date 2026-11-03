<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases decided to be no acquisition (general costs), so the list of
 * acquisitions no longer asks about them (2026-11-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('journal_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_skips');
    }
};
