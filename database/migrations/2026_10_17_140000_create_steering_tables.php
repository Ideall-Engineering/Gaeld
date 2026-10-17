<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 009, stage 5: how project results and cost blocks are shared among
 * people, and what each person draws according to plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Share of a project's (or an area's, inherited below) value per person.
        Schema::create('project_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cost_center_id')->constrained('cost_centers')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('share', 7, 4);
            $table->timestamps();

            $table->unique(['cost_center_id', 'employee_id']);
        });

        // Who bears a cost block, and who is credited for having done it.
        Schema::create('budget_group_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('carry_share', 7, 4)->default(0);
            $table->decimal('credit_share', 7, 4)->default(0);
            $table->timestamps();

            $table->unique(['budget_group_id', 'employee_id']);
        });

        // Planned drawings of a person in a year: salary, 13th, employer
        // contributions, allowances, bonuses.
        Schema::create('steering_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('label', 120);
            $table->decimal('amount', 15, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steering_items');
        Schema::dropIfExists('budget_group_shares');
        Schema::dropIfExists('project_shares');
    }
};
