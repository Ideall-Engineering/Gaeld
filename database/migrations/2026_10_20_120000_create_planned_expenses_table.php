<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 012, stage 3: single expenses known ahead that no other record holds
 * yet (a tax bill, a yearly premium), shown among the expected expenses on
 * the steering page until they are marked done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planned_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('label', 120);
            $table->decimal('amount', 15, 2);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->string('account_code', 20)->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planned_expenses');
    }
};
