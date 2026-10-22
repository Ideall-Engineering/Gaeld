<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal settlements between people (2026-10-22): one pays the other for
 * work done for the whole company, such as the bookkeeping or the tax
 * return, optionally for a job. Steering only; nothing is booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('label', 120);
            $table->foreignUuid('from_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('to_employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['organization_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_settlements');
    }
};
