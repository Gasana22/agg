<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 8: supplier invoices are paid through Finance payments, and a wrong one is cancelled. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->decimal('paid_amount', 16, 2)->default(0)->after('amount');
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
        });
        Ddl::check('supplier_invoices', 'supplier_invoices_paid_check', 'paid_amount >= 0 AND paid_amount <= amount');
    }

    public function down(): void
    {
        Ddl::dropCheck('supplier_invoices', 'supplier_invoices_paid_check');
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['paid_amount', 'cancelled_at', 'cancel_reason']);
        });
    }
};
