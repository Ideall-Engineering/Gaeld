<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring expense counts in the plan of its account from its first
 * due day on. Payments that arrive through the bank anyway (rent by
 * standing order) are planned only: no pending expense is created, which
 * would book them twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('frequency');
            $table->boolean('creates_expense')->default(true)->after('is_active');
        });

        DB::table('recurring_expenses')->whereNull('starts_on')->update(['starts_on' => DB::raw('next_due_date')]);
    }

    public function down(): void
    {
        Schema::table('recurring_expenses', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'creates_expense']);
        });
    }
};
