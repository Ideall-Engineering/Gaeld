<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->uuid('reversal_of_entry_id')->nullable()->after('is_posted');

            $table->foreign('reversal_of_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();
            $table->unique('reversal_of_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_entry_id']);
            $table->dropUnique(['reversal_of_entry_id']);
            $table->dropColumn('reversal_of_entry_id');
        });
    }
};
