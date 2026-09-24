<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipments to customers (Phase 9, docs/07 §2): dispatching creates a
 * `shipment` trace batch linked `ship` from the batches sent, and delivery
 * adds an event, so a batch's forward journey ends at the customer.
 * Lines are append-only: a shipment that left cannot be changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);

        Schema::create('shipments', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // SHP-001
            $table->string('status', 20)->default('dispatched');
            $table->uuid('customer_id');
            $table->uuid('customer_invoice_id')->nullable();
            $table->uuid('trace_batch_id');
            $table->string('destination', 300)->nullable();
            $table->string('vehicle', 60)->nullable();
            $table->string('driver', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->dateTime('dispatched_at');
            $table->foreignUuid('dispatched_by')->nullable()->constrained('users');
            $table->dateTime('delivered_at')->nullable();
            $table->string('received_by', 120)->nullable();
            $table->string('failure_reason', 300)->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status', 'dispatched_at']);
            $fk($table, 'customer_id', 'customers', 'shipments_customer_fk');
            $fk($table, 'customer_invoice_id', 'customer_invoices', 'shipments_invoice_fk');
            $fk($table, 'trace_batch_id', 'trace_batches', 'shipments_batch_fk');
        });
        Ddl::checkIn('shipments', 'status', ['dispatched', 'delivered', 'failed']);

        Schema::create('shipment_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('shipment_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('trace_batch_id');                           // the batch sent
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('description', 300)->nullable();
            $table->timestamp('created_at');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'trace_batch_id']);
            $fk($table, 'shipment_id', 'shipments', 'shipment_lines_shipment_fk');
            $fk($table, 'trace_batch_id', 'trace_batches', 'shipment_lines_batch_fk');
        });
        Ddl::check('shipment_lines', 'shipment_lines_quantity_check', 'quantity IS NULL OR quantity > 0');
        Ddl::appendOnly('shipment_lines');

        foreach (['shipments', 'shipment_lines'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
    }
};
