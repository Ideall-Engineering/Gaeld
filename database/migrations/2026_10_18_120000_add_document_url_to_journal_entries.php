<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the original receipt of an entry is kept (a link to a file share
 * such as Google Drive), so an entry can be checked against its source.
 * It is a pointer only, not part of the booking: it can be set on a posted
 * entry without a revision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('document_url', 2048)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn('document_url');
        });
    }
};
