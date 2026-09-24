<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement (docs/03 §7): purchase request → purchase order → delivery
 * (stock in) → supplier invoice (matched to what was received). Deliveries
 * and their lines are append-only receiving records.
 *
 * Suppliers are farm records for now; `party_id` links a supplier portal
 * account in Phase 12 (ADR-0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);
        $money = fn (Blueprint $t, string $name) => $t->decimal($name, 16, 2);

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->uuid('party_id')->nullable();                   // supplier portal account (Phase 12)
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
        });
        Schema::table('stock_lots', function (Blueprint $table) use ($fk) {
            $fk($table, 'supplier_id', 'suppliers', 'stock_lots_supplier_fk');
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->string('status', 20)->default('submitted');
            $table->date('needed_by')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
        });
        Ddl::checkIn('purchase_requests', 'status', ['submitted', 'approved', 'rejected', 'ordered', 'cancelled']);

        Schema::create('purchase_request_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('request_id');
            $table->uuid('item_id')->nullable();
            $table->string('description', 200);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 20);
            $table->decimal('estimated_unit_price', 16, 4)->nullable();   // money
            $fk($table, 'request_id', 'purchase_requests');
            $fk($table, 'item_id', 'inventory_items');
            $table->unique(['farm_id', 'id']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->uuid('supplier_id');
            $table->uuid('purchase_request_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('expected_on')->nullable();
            $table->uuid('delivery_location_id')->nullable();
            $table->char('currency', 3);
            $money($table, 'total_amount');
            $table->string('notes', 1000)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'supplier_id', 'suppliers');
            $fk($table, 'purchase_request_id', 'purchase_requests');
            $fk($table, 'delivery_location_id', 'farm_locations');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
            $table->index(['farm_id', 'supplier_id']);
        });
        Ddl::checkIn('purchase_orders', 'status', ['draft', 'approved', 'sent', 'partially_received', 'received', 'closed', 'cancelled']);

        Schema::create('purchase_order_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('order_id');
            $table->uuid('item_id');
            $table->string('description', 200)->nullable();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 16, 4);                   // money
            $table->decimal('received_quantity', 14, 3)->default(0);
            $table->decimal('invoiced_quantity', 14, 3)->default(0);
            $fk($table, 'order_id', 'purchase_orders');
            $fk($table, 'item_id', 'inventory_items');
            $table->unique(['farm_id', 'id']);
        });
        Ddl::check('purchase_order_lines', 'purchase_order_lines_qty_check', 'quantity > 0 AND unit_price >= 0 AND received_quantity >= 0 AND invoiced_quantity >= 0');

        Schema::create('deliveries', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                             // GRN-001
            $table->uuid('order_id');
            $table->uuid('location_id');
            $table->date('received_on');
            $table->string('supplier_reference', 60)->nullable();   // delivery note number
            $table->uuid('media_id')->nullable();                   // photo of the delivery note
            $table->string('note', 500)->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $fk($table, 'order_id', 'purchase_orders');
            $fk($table, 'location_id', 'farm_locations');
            $fk($table, 'media_id', 'media');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
        });

        Schema::create('delivery_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('delivery_id');
            $table->uuid('order_line_id');
            $table->decimal('quantity', 14, 3);
            $table->uuid('lot_id')->nullable();
            $table->uuid('movement_id')->nullable();
            $fk($table, 'delivery_id', 'deliveries');
            $fk($table, 'order_line_id', 'purchase_order_lines');
            $fk($table, 'lot_id', 'stock_lots');
            $fk($table, 'movement_id', 'stock_movements');
            $table->unique(['farm_id', 'id']);
        });

        Schema::create('supplier_invoices', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                             // our reference, SINV-001
            $table->string('invoice_number', 60);                   // the supplier's
            $table->uuid('supplier_id');
            $table->uuid('order_id');
            $table->date('invoice_date');
            $table->date('due_on')->nullable();
            $money($table, 'amount');
            $table->string('status', 20)->default('recorded');
            $table->uuid('ledger_entry_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'supplier_id', 'suppliers');
            $fk($table, 'order_id', 'purchase_orders');
            $fk($table, 'ledger_entry_id', 'ledger_entries', 'supplier_invoices_ledger_fk');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->unique(['farm_id', 'supplier_id', 'invoice_number']);
        });
        Ddl::checkIn('supplier_invoices', 'status', ['recorded', 'paid', 'cancelled']);

        Schema::create('supplier_invoice_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('invoice_id');
            $table->uuid('order_line_id');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 16, 4);
            $fk($table, 'invoice_id', 'supplier_invoices');
            $fk($table, 'order_line_id', 'purchase_order_lines');
            $table->unique(['farm_id', 'id']);
        });

        Ddl::appendOnly('deliveries');
        Ddl::appendOnly('delivery_lines');
        Ddl::appendOnly('supplier_invoice_lines');
        foreach (['suppliers', 'purchase_requests', 'purchase_request_lines', 'purchase_orders', 'purchase_order_lines', 'deliveries', 'delivery_lines', 'supplier_invoices', 'supplier_invoice_lines'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        foreach (['supplier_invoice_lines', 'supplier_invoices', 'delivery_lines', 'deliveries', 'purchase_order_lines', 'purchase_orders', 'purchase_request_lines', 'purchase_requests'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('stock_lots', fn (Blueprint $table) => $table->dropForeign('stock_lots_supplier_fk'));
        Schema::dropIfExists('suppliers');
    }
};
