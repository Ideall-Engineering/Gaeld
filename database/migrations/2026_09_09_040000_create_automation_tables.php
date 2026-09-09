<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping for the automations themselves.
 *
 * automation_settings answers "may this run at all here", automation_runs
 * answers "what did it do, and has this exact occasion been handled already".
 *
 * The unique index across organization, automation and event_key is the whole
 * defence against double processing: a re-imported statement, a retried job and
 * a second scheduler tick on the same day all produce the same event key, and
 * the second insert simply loses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('automation');
            $table->boolean('is_enabled')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['organization_id', 'automation']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('automation');

            // Identifies the occasion, not the attempt: the import that
            // triggered it, the day the schedule fired, the period checked.
            $table->string('event_key');

            $table->string('status');           // running | succeeded | failed | skipped
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->json('summary')->nullable();
            $table->text('message')->nullable();
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('triggered_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['organization_id', 'automation', 'event_key']);
            $table->index(['organization_id', 'automation', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_settings');
    }
};
