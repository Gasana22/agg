<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online payments through a payment gateway (ADR-0018): an owner paying the
 * subscription, or a customer paying a farm's invoice. Global like
 * subscriptions (an organization pays for its farms); a customer invoice
 * payment names its farm, and every read is filtered in code to the person
 * who paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 40)->unique();           // our tx_ref
            $table->uuid('provider_id');                          // integration_providers.id
            $table->string('provider', 40);
            $table->string('purpose', 40);                        // subscription | customer_invoice
            $table->uuid('subject_id');
            $table->string('subject_code', 40)->nullable();
            $table->uuid('farm_id')->nullable();
            $table->foreign('farm_id')->references('id')->on('farms');
            $table->decimal('amount', 16, 2);
            $table->char('currency', 3);
            $table->string('description', 200);
            $table->string('status', 20)->default('pending');
            $table->string('checkout_url', 500)->nullable();
            $table->string('provider_tx_id', 100)->nullable();
            $table->decimal('paid_amount', 16, 2)->nullable();
            $table->string('failure_reason', 300)->nullable();
            $table->string('return_path', 300);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_tx_id']);
            $table->index(['purpose', 'subject_id']);
            $table->index(['created_by', 'created_at']);
        });
        Ddl::checkIn('online_payments', 'status', ['pending', 'succeeded', 'failed', 'cancelled']);
        Ddl::checkIn('online_payments', 'purpose', ['subscription', 'customer_invoice']);
        Ddl::check('online_payments', 'online_payments_amount_check', 'amount > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
    }
};
