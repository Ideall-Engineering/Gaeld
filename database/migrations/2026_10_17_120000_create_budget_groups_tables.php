<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 009, stage 3: cost blocks of a year, each over several accounts,
 * planned and followed together. The per-account budgets stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('name', 120);
            $table->string('kind', 20)->default('running');
            $table->decimal('plan_amount', 15, 2)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'year', 'name']);
        });

        Schema::create('budget_group_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['budget_group_id', 'account_id']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_group_accounts');
        Schema::dropIfExists('budget_groups');
    }
};
