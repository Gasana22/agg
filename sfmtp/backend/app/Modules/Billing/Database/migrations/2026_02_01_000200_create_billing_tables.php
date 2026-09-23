<?php

use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SaaS billing (requirements §35, ADR-0001): configurable plans and one
 * subscription per organization. Platform data — no farm_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->decimal('price', 14, 2);
            $table->char('currency', 3)->default('UGX');
            $table->string('billing_period', 10)->default('monthly');
            $table->unsignedSmallInteger('trial_days')->nullable();      // null = platform default
            $table->unsignedInteger('max_farms')->nullable();            // null = unlimited
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_storage_mb')->nullable();
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);                  // owners can pick it themselves
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Ddl::checkIn('subscription_plans', 'billing_period', ['monthly', 'yearly']);
        Ddl::check('subscription_plans', 'subscription_plans_price_check', 'price >= 0');

        $plans = [
            ['starter', 'Starter', 'One farm and a small team.', 50000, 1, 10, 1024, ['traceability', 'mobile_app'], 1],
            ['growth', 'Growth', 'Several farms, full team, QR traceability.', 250000, 5, 50, 10240, ['traceability', 'mobile_app', 'qr_codes', 'supplier_portal', 'customer_portal'], 2],
            ['enterprise', 'Enterprise', 'Unlimited farms and users, custom terms.', 0, null, null, null, ['traceability', 'mobile_app', 'qr_codes', 'supplier_portal', 'customer_portal', 'api_access', 'priority_support'], 3],
        ];
        foreach ($plans as [$code, $name, $description, $price, $farms, $users, $storage, $features, $sort]) {
            DB::table('subscription_plans')->insert([
                'id' => (string) Str::uuid7(),
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'price' => $price,
                'currency' => 'UGX',
                'billing_period' => 'monthly',
                'max_farms' => $farms,
                'max_users' => $users,
                'max_storage_mb' => $storage,
                'features' => json_encode($features),
                'is_active' => true,
                'is_public' => $code !== 'enterprise',
                'sort_order' => $sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained();
            $table->foreignUuid('plan_id')->constrained('subscription_plans');
            $table->string('status', 20);
            $table->date('current_period_start');
            $table->date('current_period_end');
            $table->date('grace_until')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'current_period_end']);
            $table->index(['status', 'grace_until']);
        });
        Ddl::checkIn('subscriptions', 'status', SubscriptionStatus::values());

        Schema::create('subscription_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('event', 40);             // trial_started, payment_received, plan_changed …
            $table->json('details')->nullable();
            $table->foreignUuid('changed_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $table->index(['subscription_id', 'created_at']);
        });
        Ddl::appendOnly('subscription_status_history');

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('provider', 30);          // manual now; gateways in Phase 14
            $table->string('provider_ref', 120);
            $table->string('status', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['provider', 'provider_ref']);
            $table->index(['subscription_id', 'created_at']);
            $table->index(['status', 'paid_at']);
        });
        Ddl::checkIn('subscription_payments', 'status', ['pending', 'succeeded', 'failed', 'refunded']);
        Ddl::check('subscription_payments', 'subscription_payments_amount_check', 'amount >= 0');
        // Payments are financial records: never deleted.
        Ddl::appendOnly('subscription_payments', ['DELETE']);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscription_status_history');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
