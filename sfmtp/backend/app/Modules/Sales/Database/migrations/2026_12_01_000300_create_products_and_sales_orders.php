<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Products and sales orders (Phase 12, ADR-0003, ADR-0016).
 * - Products carry the farm's list price, set by whoever holds
 *   sales.pricing.manage; published ones appear in the customer portal.
 * - A sales order is placed in the portal or recorded by staff:
 *   requested → approved (or rejected) → invoiced → dispatched →
 *   delivered, or cancelled before anything leaves. Order lines keep the
 *   price at the time of ordering.
 * - Shipments and their lines point back to the order they fulfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);
        $money = fn (Blueprint $t, string $name) => $t->decimal($name, 16, 2);

        Schema::create('products', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // PRD-001
            $table->string('name', 150);
            $table->string('description', 1000)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('unit', 20);
            $money($table, 'list_price');
            $table->char('currency', 3);
            $table->decimal('min_order_quantity', 14, 3)->nullable();
            $table->string('availability_note', 200)->nullable();
            $table->uuid('inventory_item_id')->nullable();
            $table->uuid('income_account_id')->nullable();
            $table->uuid('media_id')->nullable();                    // a photo
            $table->boolean('is_published')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'inventory_item_id', 'inventory_items', 'products_item_fk');
            $fk($table, 'income_account_id', 'ledger_accounts', 'products_account_fk');
            $fk($table, 'media_id', 'media', 'products_media_fk');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'is_published', 'is_active']);
        });
        Ddl::check('products', 'products_price_check', 'list_price >= 0 AND (min_order_quantity IS NULL OR min_order_quantity > 0)');

        Schema::create('sales_orders', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // SO-0001
            $table->uuid('customer_id');
            $table->string('status', 20)->default('requested');
            $table->string('source', 20);                            // portal | internal
            $table->char('currency', 3);
            $money($table, 'total_amount');
            $table->date('requested_delivery_on')->nullable();
            $table->string('delivery_address', 300)->nullable();
            $table->string('customer_note', 500)->nullable();
            $table->string('internal_note', 500)->nullable();
            $table->foreignUuid('placed_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 300)->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->uuid('customer_invoice_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'customer_id', 'customers', 'sales_orders_customer_fk');
            $fk($table, 'customer_invoice_id', 'customer_invoices', 'sales_orders_invoice_fk');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
            $table->index(['farm_id', 'customer_id']);
        });
        Ddl::checkIn('sales_orders', 'status', ['requested', 'approved', 'rejected', 'invoiced', 'dispatched', 'delivered', 'cancelled']);
        Ddl::checkIn('sales_orders', 'source', ['portal', 'internal']);
        Ddl::check('sales_orders', 'sales_orders_total_check', 'total_amount >= 0');

        Schema::create('sales_order_lines', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('order_id');
            $table->unsignedSmallInteger('position');
            $table->uuid('product_id');
            $table->string('description', 300);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 20);
            $money($table, 'unit_price');
            $money($table, 'amount');
            $table->decimal('dispatched_quantity', 14, 3)->default(0);
            $table->timestamps();
            $fk($table, 'order_id', 'sales_orders', 'sales_order_lines_order_fk');
            $fk($table, 'product_id', 'products', 'sales_order_lines_product_fk');
            $table->unique(['farm_id', 'id']);
        });
        Ddl::check('sales_order_lines', 'sales_order_lines_qty_check', 'quantity > 0 AND unit_price >= 0 AND amount >= 0 AND dispatched_quantity >= 0');

        Schema::table('shipments', function (Blueprint $table) use ($fk) {
            $table->uuid('sales_order_id')->nullable();
            $fk($table, 'sales_order_id', 'sales_orders', 'shipments_order_fk');
            $table->index(['farm_id', 'sales_order_id']);
        });
        Schema::table('shipment_lines', function (Blueprint $table) use ($fk) {
            $table->uuid('sales_order_line_id')->nullable();
            $fk($table, 'sales_order_line_id', 'sales_order_lines', 'shipment_lines_order_line_fk');
        });

        foreach (['products', 'sales_orders', 'sales_order_lines'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        Schema::table('shipment_lines', function (Blueprint $table) {
            $table->dropForeign('shipment_lines_order_line_fk');
            $table->dropColumn('sales_order_line_id');
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign('shipments_order_fk');
            $table->dropIndex(['farm_id', 'sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('products');
    }
};
