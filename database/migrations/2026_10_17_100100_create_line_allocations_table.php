<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 009: which project and/or person a transaction line belongs to, and
 * with what share. Kept beside the lines on purpose: the ledger tables are
 * never written, so an allocation can change in any period, archived or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('line_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_line_id')->constrained('transaction_lines')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->restrictOnDelete();
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->decimal('share', 7, 4);
            $table->string('source', 20)->default('manual');
            $table->timestamps();

            $table->index('transaction_line_id');
            $table->index(['organization_id', 'cost_center_id']);
            $table->index(['organization_id', 'employee_id']);
        });

        DB::statement('ALTER TABLE line_allocations ADD CONSTRAINT line_allocations_target_check CHECK (cost_center_id IS NOT NULL OR employee_id IS NOT NULL)');
        DB::statement('ALTER TABLE line_allocations ADD CONSTRAINT line_allocations_share_check CHECK (share > 0 AND share <= 100)');

        // Carry over anything an earlier version stored in the free-text
        // transaction_lines.cost_center_id column, as a 100 % allocation.
        DB::statement(<<<'SQL'
            INSERT INTO line_allocations (organization_id, transaction_line_id, cost_center_id, share, source, created_at, updated_at)
            SELECT je.organization_id, tl.id, cc.id, 100, 'legacy', NOW(), NOW()
            FROM transaction_lines tl
            JOIN journal_entries je ON je.id = tl.journal_entry_id
            JOIN cost_centers cc ON cc.organization_id = je.organization_id
                AND cc.id::text = tl.cost_center_id
            WHERE tl.cost_center_id IS NOT NULL AND tl.cost_center_id <> ''
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('line_allocations');
    }
};
