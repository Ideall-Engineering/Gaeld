<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 009: a cost centre doubles as a project (area, customer project or
 * internal block) with a status, customer, owner, target date and the
 * amount it is expected to bring in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->string('kind', 20)->default('project')->after('parent_id');
            $table->string('status', 20)->default('in_progress')->after('kind');
            $table->foreignId('contact_id')->nullable()->after('status')->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('responsible_employee_id')->nullable()->after('contact_id')->constrained('employees')->nullOnDelete();
            $table->date('target_date')->nullable()->after('responsible_employee_id');
            $table->decimal('expected_amount', 15, 2)->nullable()->after('target_date');
            $table->text('notes')->nullable()->after('expected_amount');

            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropConstrainedForeignId('contact_id');
            $table->dropConstrainedForeignId('responsible_employee_id');
            $table->dropColumn(['kind', 'status', 'target_date', 'expected_amount', 'notes']);
        });
    }
};
