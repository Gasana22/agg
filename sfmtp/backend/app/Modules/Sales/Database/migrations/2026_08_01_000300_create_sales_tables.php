<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers and customer invoices (docs/03 §8). Invoices are drafted, then
 * issued (posting Dr receivables, Cr income per line) and paid through
 * Finance payments; a wrong one is voided, which reverses its entry.
 * Sales orders and products follow in Phase 12; `party_id` links a
 * customer portal account then (ADR-0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);
        $money = fn (Blueprint $t, string $name) => $t->decimal($name, 16, 2);

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // CUS-001
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->uuid('party_id')->nullable();                    // customer portal account (Phase 12)
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
        });

        Schema::create('customer_invoices', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // INV-0001
            $table->string('status', 20)->default('draft');
            $table->uuid('customer_id');
            $table->date('invoice_date');
            $table->date('due_on')->nullable();
            $money($table, 'amount')->default(0);
            $money($table, 'paid_amount')->default(0);
            $table->string('notes', 500)->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->foreignUuid('issued_by')->nullable()->constrained('users');
            $table->timestamp('issued_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status', 'due_on']);
            $fk($table, 'customer_id', 'customers', 'customer_invoices_customer_fk');
            $fk($table, 'ledger_entry_id', 'ledger_entries', 'customer_invoices_entry_fk');
        });
        Ddl::checkIn('customer_invoices', 'status', ['draft', 'issued', 'paid', 'void']);
        Ddl::check('customer_invoices', 'customer_invoices_amount_check', 'amount >= 0 AND paid_amount >= 0 AND paid_amount <= amount');

        Schema::create('customer_invoice_lines', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('invoice_id');
            $table->unsignedSmallInteger('position');
            $table->string('description', 300);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 20)->nullable();
            $money($table, 'unit_price');
            $money($table, 'amount');
            $table->uuid('account_id');                              // an income account
            $table->string('cost_center_type', 20)->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->string('cost_center_label', 150)->nullable();
            $table->uuid('animal_sale_id')->nullable();              // a completed livestock sale being billed
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'animal_sale_id']);
            $fk($table, 'invoice_id', 'customer_invoices', 'customer_invoice_lines_invoice_fk');
            $fk($table, 'account_id', 'ledger_accounts', 'customer_invoice_lines_account_fk');
            $fk($table, 'animal_sale_id', 'animal_sale_requests', 'customer_invoice_lines_sale_fk');
        });
        Ddl::check('customer_invoice_lines', 'customer_invoice_lines_amount_check', 'quantity > 0 AND unit_price >= 0 AND amount >= 0');

        foreach (['customers', 'customer_invoices', 'customer_invoice_lines'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_lines');
        Schema::dropIfExists('customer_invoices');
        Schema::dropIfExists('customers');
    }
};
