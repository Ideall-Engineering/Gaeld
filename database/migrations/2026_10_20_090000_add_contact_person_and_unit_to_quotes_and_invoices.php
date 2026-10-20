<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 011: quotes and invoices name a contact person of the customer,
 * carry a delivery note and an intro text, and each line a unit. A contact
 * person gets a department for the address block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_persons', function (Blueprint $table) {
            $table->string('department')->nullable()->after('position');
        });

        foreach (['quotes', 'invoices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignUuid('contact_person_id')->nullable()->after('customer_snapshot')
                    ->constrained('contact_persons')->nullOnDelete();
                $table->string('delivery_note')->nullable()->after('payment_terms');
                $table->text('intro_text')->nullable()->after('delivery_note');
            });
        }

        foreach (['quote_lines', 'invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('unit', 20)->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        foreach (['quote_lines', 'invoice_lines'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }

        foreach (['quotes', 'invoices'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('contact_person_id');
                $table->dropColumn(['delivery_note', 'intro_text']);
            });
        }

        Schema::table('contact_persons', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }
};
