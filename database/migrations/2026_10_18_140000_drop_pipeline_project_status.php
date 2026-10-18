<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The status "pipeline" is gone; "possible" now reads "quote sent" and is
 * set by the quotes of a job. Jobs still in the pipeline were planned and
 * agreed, so they continue as in progress (decided 2026-10-18).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cost_centers')->where('status', 'pipeline')->update(['status' => 'in_progress']);
    }

    public function down(): void
    {
        // Which jobs were in the pipeline is not kept; nothing to undo.
    }
};
