<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance documents (docs/03 §8): expenses, other income, payments, payroll
 * and budgets. Each posts to the ledger; none is ever deleted. A mistake is
 * voided, which posts the reversal of the document's entry.
 *
 * Cost centres (`cost_center_type` / `cost_center_id`) are the work subjects
 * of docs/03 §6: a crop cycle, plot, location, animal or animal group.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);
        $money = fn (Blueprint $t, string $name) => $t->decimal($name, 16, 2);
        $costCenter = function (Blueprint $t) {
            $t->string('cost_center_type', 20)->nullable();
            $t->uuid('cost_center_id')->nullable();
            $t->string('cost_center_label', 150)->nullable();
        };

        // Money accounts (cash, mobile money, bank) are the ones payments use.
        Schema::table('ledger_accounts', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('type');
            $table->string('description', 300)->nullable()->after('name');
        });
        DB::table('ledger_accounts')->whereIn('code', ['1000', '1010'])->update(['is_cash' => true]);

        Schema::create('expenses', function (Blueprint $table) use ($fk, $money, $costCenter) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // EXP-001
            $table->string('status', 20)->default('requested');
            $table->uuid('account_id');                              // an expense account
            $money($table, 'amount');
            $money($table, 'paid_amount')->default(0);
            $table->date('spent_on');
            $table->string('payee', 150)->nullable();
            $table->string('description', 300);
            $costCenter($table);
            $table->uuid('paid_from_account_id')->nullable();        // already paid in cash / mobile money
            $table->uuid('media_id')->nullable();                    // receipt
            $table->uuid('ledger_entry_id')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
            $fk($table, 'account_id', 'ledger_accounts', 'expenses_account_fk');
            $fk($table, 'paid_from_account_id', 'ledger_accounts', 'expenses_paid_from_fk');
            $fk($table, 'media_id', 'media', 'expenses_media_fk');
            $fk($table, 'ledger_entry_id', 'ledger_entries', 'expenses_entry_fk');
        });
        Ddl::checkIn('expenses', 'status', ['requested', 'approved', 'paid', 'rejected', 'cancelled', 'void']);
        Ddl::check('expenses', 'expenses_amount_check', 'amount > 0 AND paid_amount >= 0 AND paid_amount <= amount');

        Schema::create('income_records', function (Blueprint $table) use ($fk, $money, $costCenter) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // INC-001
            $table->string('status', 20)->default('recorded');
            $table->uuid('account_id');                              // an income account
            $table->uuid('received_into_account_id');                // a money account
            $money($table, 'amount');
            $table->date('received_on');
            $table->string('payer', 150)->nullable();
            $table->string('description', 300);
            $costCenter($table);
            $table->uuid('media_id')->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $fk($table, 'account_id', 'ledger_accounts', 'income_account_fk');
            $fk($table, 'received_into_account_id', 'ledger_accounts', 'income_into_fk');
            $fk($table, 'media_id', 'media', 'income_media_fk');
            $fk($table, 'ledger_entry_id', 'ledger_entries', 'income_entry_fk');
        });
        Ddl::checkIn('income_records', 'status', ['recorded', 'void']);
        Ddl::check('income_records', 'income_amount_check', 'amount > 0');

        // One payment settles one document: a customer or supplier invoice, an expense or a payroll run.
        Schema::create('payments', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // PMT-0001
            $table->string('direction', 3);                          // in | out
            $table->string('status', 10)->default('posted');
            $table->string('payable_type', 30);
            $table->uuid('payable_id');
            $table->string('payable_code', 30);
            $table->string('party', 150)->nullable();
            $money($table, 'amount');
            $table->date('paid_on');
            $table->string('method', 20);
            $table->uuid('account_id');                              // the money account
            $table->string('reference', 100)->nullable();            // mobile money / bank reference
            $table->string('note', 300)->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'payable_type', 'payable_id']);
            $table->index(['farm_id', 'paid_on']);
            $fk($table, 'account_id', 'ledger_accounts', 'payments_account_fk');
            $fk($table, 'ledger_entry_id', 'ledger_entries', 'payments_entry_fk');
        });
        Ddl::checkIn('payments', 'direction', ['in', 'out']);
        Ddl::checkIn('payments', 'status', ['posted', 'void']);
        Ddl::checkIn('payments', 'method', ['cash', 'mobile_money', 'bank', 'cheque', 'other']);
        Ddl::check('payments', 'payments_amount_check', 'amount > 0');

        Schema::create('payroll_runs', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // PRL-001
            $table->string('status', 20)->default('draft');
            $table->date('period_start');
            $table->date('period_end');
            $money($table, 'total_gross')->default(0);
            $money($table, 'total_deductions')->default(0);
            $money($table, 'total_net')->default(0);
            $money($table, 'paid_amount')->default(0);
            $table->string('notes', 500)->nullable();
            $table->uuid('ledger_entry_id')->nullable();
            $table->foreignUuid('prepared_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $fk($table, 'ledger_entry_id', 'ledger_entries', 'payroll_runs_entry_fk');
        });
        Ddl::checkIn('payroll_runs', 'status', ['draft', 'approved', 'paid', 'cancelled']);
        Ddl::check('payroll_runs', 'payroll_runs_period_check', 'period_end >= period_start AND paid_amount >= 0 AND paid_amount <= total_net');

        Schema::create('payroll_lines', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('run_id');
            $table->uuid('worker_id');
            $table->unsignedSmallInteger('days_worked');
            $table->unsignedInteger('minutes_worked');
            $table->unsignedInteger('tasks_verified');
            $money($table, 'daily_rate');
            $money($table, 'bonus')->default(0);
            $money($table, 'gross');
            $money($table, 'deductions')->default(0);
            $money($table, 'net');
            $table->string('note', 300)->nullable();
            $table->json('allocation')->nullable();                  // [{type, id, label, amount}] from verified task time
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'run_id', 'worker_id']);
            $fk($table, 'run_id', 'payroll_runs', 'payroll_lines_run_fk');
            $fk($table, 'worker_id', 'workers', 'payroll_lines_worker_fk');
        });
        Ddl::check('payroll_lines', 'payroll_lines_amount_check', 'gross >= 0 AND deductions >= 0 AND net >= 0 AND bonus >= 0');

        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                              // BUD-001
            $table->string('name', 150);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('scope_type', 20)->nullable();            // null: the whole farm
            $table->uuid('scope_id')->nullable();
            $table->string('scope_label', 150)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
        });
        Ddl::checkIn('budgets', 'status', ['active', 'archived']);
        Ddl::check('budgets', 'budgets_period_check', 'period_end >= period_start');

        Schema::create('budget_lines', function (Blueprint $table) use ($fk, $money) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('budget_id');
            $table->uuid('account_id');
            $money($table, 'amount');
            $table->string('note', 300)->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'budget_id', 'account_id']);
            $fk($table, 'budget_id', 'budgets', 'budget_lines_budget_fk');
            $fk($table, 'account_id', 'ledger_accounts', 'budget_lines_account_fk');
        });
        Ddl::check('budget_lines', 'budget_lines_amount_check', 'amount >= 0');

        foreach (['expenses', 'income_records', 'payments', 'payroll_runs', 'payroll_lines', 'budgets', 'budget_lines'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        foreach (['budget_lines', 'budgets', 'payroll_lines', 'payroll_runs', 'payments', 'income_records', 'expenses'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('ledger_accounts', fn (Blueprint $table) => $table->dropColumn(['is_cash', 'description']));
    }
};
