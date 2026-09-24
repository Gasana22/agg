<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The supplier portal (Phase 12, ADR-0016).
 * - A sent order gets the supplier's answer: accepted (with the quantities
 *   and date it can deliver) or rejected, with a note.
 * - Dispatch notices announce goods on the way; the store receives against
 *   one, and the delivery (GRN) stays the stock record.
 * - Invoice submissions wait for the farm to record them through the
 *   normal three-way match, or to send them back with a reason. The
 *   supplier never writes to the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('supplier_response', 20)->nullable();
            $table->timestamp('supplier_responded_at')->nullable();
            $table->foreignUuid('supplier_responded_by')->nullable()->constrained('users');
            $table->date('supplier_promised_on')->nullable();
            $table->string('supplier_note', 500)->nullable();
        });
        Ddl::check('purchase_orders', 'purchase_orders_supplier_response_check', "supplier_response IS NULL OR supplier_response IN ('accepted', 'rejected')");

        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->decimal('confirmed_quantity', 14, 3)->nullable();
        });

        Schema::create('supplier_dispatches', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // ASN-001
            $table->uuid('order_id');
            $table->string('status', 20)->default('dispatched');
            $table->date('dispatched_on');
            $table->date('expected_on')->nullable();
            $table->string('reference', 60)->nullable();             // the supplier's delivery note number
            $table->uuid('media_id')->nullable();                    // the delivery note
            $table->string('vehicle', 60)->nullable();
            $table->string('driver', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->uuid('delivery_id')->nullable();                 // the GRN that received it
            $table->timestamp('received_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'order_id', 'purchase_orders');
            $fk($table, 'media_id', 'media');
            $fk($table, 'delivery_id', 'deliveries');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'order_id']);
        });
        Ddl::checkIn('supplier_dispatches', 'status', ['dispatched', 'received', 'cancelled']);

        Schema::create('supplier_dispatch_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('dispatch_id');
            $table->uuid('order_line_id');
            $table->decimal('quantity', 14, 3);
            $fk($table, 'dispatch_id', 'supplier_dispatches');
            $fk($table, 'order_line_id', 'purchase_order_lines');
            $table->unique(['farm_id', 'id']);
        });
        Ddl::check('supplier_dispatch_lines', 'supplier_dispatch_lines_qty_check', 'quantity > 0');
        Ddl::appendOnly('supplier_dispatch_lines');

        Schema::create('supplier_invoice_submissions', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // SUB-001
            $table->uuid('order_id');
            $table->uuid('supplier_id');
            $table->string('status', 20)->default('submitted');
            $table->string('invoice_number', 60);
            $table->date('invoice_date');
            $table->date('due_on')->nullable();
            $table->decimal('amount', 16, 2);
            $table->json('lines');                                   // [{order_line_id, quantity, unit_price}]
            $table->uuid('media_id')->nullable();                    // the invoice document
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users');
            $table->uuid('supplier_invoice_id')->nullable();         // once recorded
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'order_id', 'purchase_orders');
            $fk($table, 'supplier_id', 'suppliers');
            $fk($table, 'media_id', 'media');
            $fk($table, 'supplier_invoice_id', 'supplier_invoices');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
        });
        Ddl::checkIn('supplier_invoice_submissions', 'status', ['submitted', 'recorded', 'rejected']);

        foreach (['supplier_dispatches', 'supplier_dispatch_lines', 'supplier_invoice_submissions'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_submissions');
        Schema::dropIfExists('supplier_dispatch_lines');
        Schema::dropIfExists('supplier_dispatches');
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropColumn('confirmed_quantity'));
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_responded_by');
            $table->dropColumn(['supplier_response', 'supplier_responded_at', 'supplier_promised_on', 'supplier_note']);
        });
    }
};
