<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module-prefixed table for tracking async module operations (plan.md
 * "Persistentes Modul-Auftragsmodell"). No core table is touched or
 * shadowed. Currently unused — the correction endpoints Phase 4 adds are
 * synchronous — kept ready for the first module operation that needs an
 * async job (e.g. a future bulk-correction import).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accountant_api_job_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('job_type', 100);
            $table->string('status', 20)->default('pending');
            $table->jsonb('payload')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'job_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accountant_api_job_statuses');
    }
};
