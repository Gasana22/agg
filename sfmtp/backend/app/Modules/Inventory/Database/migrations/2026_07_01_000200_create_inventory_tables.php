<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory (docs/03 §7).
 *
 * - `stock_movements` is the append-only stock ledger (signed quantities and
 *   values). `stock_balances` is its projection per item, store and lot,
 *   updated in the same transaction under a row lock. A check constraint
 *   keeps quantities at or above zero unless the farm allows negative stock
 *   (the flag is copied onto the balance row when it is written).
 * - Every received lot is a traceability batch (`input_lot`), so inputs can
 *   be followed from supplier to field.
 * - Stores are farm locations; every reference is a composite (farm_id, …) key.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);

        // Per-farm counters for codes created in parallel (App\Support\Database\Sequence).
        Schema::create('farm_sequences', function (Blueprint $table) {
            $table->foreignUuid('farm_id')->constrained();
            $table->string('name', 40);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->primary(['farm_id', 'name']);
        });

        Schema::create('inventory_items', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                             // ITM-001
            $table->string('name', 150);
            $table->foreignUuid('category_id')->constrained('global_inventory_categories');
            $table->string('unit', 20);                             // a units.code
            $table->string('sku', 60)->nullable();
            $table->decimal('reorder_level', 14, 3)->nullable();
            $table->boolean('tracks_lots')->default(true);
            $table->boolean('tracks_expiry')->default(false);
            $table->uuid('default_location_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'default_location_id', 'farm_locations');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'category_id']);
        });

        Schema::create('stock_lots', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('item_id');
            $table->string('code', 20);                             // LOT-0001
            $table->string('lot_number', 60)->nullable();           // the supplier's
            $table->date('expires_on')->nullable();
            $table->date('received_on');
            $table->decimal('unit_cost', 16, 4)->nullable();        // money
            $table->uuid('supplier_id')->nullable();                // FK added with suppliers
            $table->uuid('trace_batch_id')->nullable();
            $table->string('source_type', 30);                      // delivery | opening | adjustment
            $table->uuid('source_id')->nullable();
            $table->timestamp('created_at');
            $fk($table, 'item_id', 'inventory_items');
            $fk($table, 'trace_batch_id', 'trace_batches', 'stock_lots_trace_batch_fk');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'item_id', 'expires_on']);
        });

        Schema::create('stock_balances', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('item_id');
            $table->uuid('location_id');
            $table->uuid('lot_id')->nullable();
            $table->string('lot_key', 36)->default('');             // lot id, or '' for untracked stock (unique with NULLs)
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('value', 16, 2)->default(0);            // money
            $table->boolean('allow_negative')->default(false);
            $table->timestamp('updated_at')->nullable();
            $fk($table, 'item_id', 'inventory_items');
            $fk($table, 'location_id', 'farm_locations');
            $fk($table, 'lot_id', 'stock_lots');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'item_id', 'location_id', 'lot_key']);
            $table->index(['farm_id', 'location_id']);
        });
        Ddl::check('stock_balances', 'stock_balances_quantity_check', 'quantity >= 0 OR allow_negative');

        Schema::create('stock_movements', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('item_id');
            $table->uuid('lot_id')->nullable();
            $table->uuid('location_id');
            $table->string('type', 20);
            $table->decimal('quantity', 14, 3);                     // signed
            $table->decimal('unit_cost', 16, 4)->nullable();        // money
            $table->decimal('value', 16, 2)->default(0);            // signed money
            $table->decimal('balance_after', 14, 3);
            $table->string('source_type', 30);
            $table->uuid('source_id')->nullable();
            $table->string('subject_type', 20)->nullable();         // what it was issued to (cost centre)
            $table->uuid('subject_id')->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('occurred_at', 6);
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $fk($table, 'item_id', 'inventory_items');
            $fk($table, 'lot_id', 'stock_lots');
            $fk($table, 'location_id', 'farm_locations');
            $fk($table, 'ledger_entry_id', 'ledger_entries', 'stock_movements_ledger_fk');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'item_id', 'occurred_at']);
            $table->index(['farm_id', 'source_type', 'source_id']);
            $table->index(['farm_id', 'occurred_at']);
        });
        Ddl::checkIn('stock_movements', 'type', ['receipt', 'opening', 'issue', 'return', 'transfer_out', 'transfer_in', 'adjustment']);

        Schema::create('stock_transfers', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->uuid('from_location_id');
            $table->uuid('to_location_id');
            $table->string('note', 500)->nullable();
            $table->foreignUuid('transferred_by')->nullable()->constrained('users');
            $table->timestamp('occurred_at', 6);
            $table->timestamp('created_at', 6);
            $fk($table, 'from_location_id', 'farm_locations', 'stock_transfers_from_fk');
            $fk($table, 'to_location_id', 'farm_locations', 'stock_transfers_to_fk');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->uuid('location_id');
            $table->string('status', 20)->default('proposed');
            $table->string('reason', 500);
            $table->decimal('value_change', 16, 2)->nullable();     // money, at approval
            $table->foreignUuid('proposed_by')->nullable()->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'location_id', 'farm_locations');
            $fk($table, 'ledger_entry_id', 'ledger_entries', 'stock_adjustments_ledger_fk');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
        });
        Ddl::checkIn('stock_adjustments', 'status', ['proposed', 'approved', 'rejected', 'cancelled']);

        Schema::create('stock_adjustment_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('adjustment_id');
            $table->uuid('item_id');
            $table->uuid('lot_id')->nullable();
            $table->decimal('expected_quantity', 14, 3);            // the books when proposed
            $table->decimal('counted_quantity', 14, 3);
            $fk($table, 'adjustment_id', 'stock_adjustments');
            $fk($table, 'item_id', 'inventory_items');
            $fk($table, 'lot_id', 'stock_lots');
            $table->unique(['farm_id', 'id']);
        });

        Schema::create('inventory_requests', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                             // REQ-001
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->uuid('task_id')->nullable();
            $table->string('subject_type', 20);                     // crop_cycle | animal_group | animal | plot | location | general
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label', 150)->nullable();
            $table->uuid('location_id')->nullable();                // the store to issue from
            $table->date('needed_on')->nullable();
            $table->string('status', 20)->default('requested');
            $table->string('note', 500)->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'task_id', 'worker_tasks');
            $fk($table, 'location_id', 'farm_locations');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
        });
        Ddl::checkIn('inventory_requests', 'status', ['requested', 'approved', 'rejected', 'partially_issued', 'issued', 'cancelled']);

        Schema::create('inventory_request_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('request_id');
            $table->uuid('item_id');
            $table->decimal('quantity', 14, 3);
            $table->decimal('issued_quantity', 14, 3)->default(0);
            $fk($table, 'request_id', 'inventory_requests');
            $fk($table, 'item_id', 'inventory_items');
            $table->unique(['farm_id', 'id']);
        });
        Ddl::check('inventory_request_lines', 'inventory_request_lines_qty_check', 'quantity > 0 AND issued_quantity >= 0 AND issued_quantity <= quantity');

        Ddl::appendOnly('stock_movements');
        Ddl::appendOnly('stock_transfers');
        Ddl::appendOnly('stock_lots', ['DELETE']);
        foreach (['farm_sequences', 'inventory_items', 'stock_lots', 'stock_balances', 'stock_movements', 'stock_transfers', 'stock_adjustments', 'stock_adjustment_lines', 'inventory_requests', 'inventory_request_lines'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        foreach (['inventory_request_lines', 'inventory_requests', 'stock_adjustment_lines', 'stock_adjustments', 'stock_transfers', 'stock_movements', 'stock_balances', 'stock_lots', 'inventory_items', 'farm_sequences'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
