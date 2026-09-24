<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The double-entry ledger core (docs/03 §8). Every money effect of stock
 * and purchasing posts a balanced entry; entries are never changed, only
 * reversed. On PostgreSQL a deferred constraint trigger also refuses an
 * unbalanced entry at commit, whatever wrote it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 10);
            $table->string('name', 120);
            $table->string('type', 10);
            $table->boolean('is_system')->default(false);   // used by automatic postings
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
        });
        Ddl::checkIn('ledger_accounts', 'type', ['asset', 'liability', 'equity', 'income', 'expense']);

        // Entry numbers come from a per-farm counter taken under a row lock.
        Schema::create('ledger_sequences', function (Blueprint $table) {
            $table->foreignUuid('farm_id')->primary()->constrained();
            $table->unsignedBigInteger('last_number')->default(0);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('number', 20);                     // JE-00001
            $table->date('posted_on');
            $table->string('source_type', 40);
            $table->uuid('source_id')->nullable();
            $table->string('memo', 300);
            $table->uuid('reverses_entry_id')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'number']);
            $table->unique(['farm_id', 'reverses_entry_id']);   // reversed at most once
            $table->index(['farm_id', 'source_type', 'source_id']);
            $table->index(['farm_id', 'posted_on']);
        });
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->foreign(['farm_id', 'reverses_entry_id'], 'ledger_entries_reverses_fk')->references(['farm_id', 'id'])->on('ledger_entries');
        });

        Schema::create('ledger_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('entry_id');
            $table->uuid('account_id');
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->string('cost_center_type', 20)->nullable();   // crop_cycle | animal_group | animal | general …
            $table->uuid('cost_center_id')->nullable();
            $table->string('memo', 200)->nullable();
            $table->foreign(['farm_id', 'entry_id'])->references(['farm_id', 'id'])->on('ledger_entries');
            $table->foreign(['farm_id', 'account_id'])->references(['farm_id', 'id'])->on('ledger_accounts');
            $table->index(['farm_id', 'account_id']);
            $table->index(['farm_id', 'entry_id']);
            $table->index(['farm_id', 'cost_center_type', 'cost_center_id']);
        });
        Ddl::check('ledger_lines', 'ledger_lines_amount_check', '(debit >= 0 AND credit >= 0) AND ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');

        if (Ddl::isPgsql()) {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION sfmtp_ledger_entry_balanced() RETURNS trigger AS $$
                DECLARE diff numeric;
                BEGIN
                    SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) INTO diff FROM ledger_lines WHERE entry_id = NEW.entry_id;
                    IF diff <> 0 THEN
                        RAISE EXCEPTION 'SFMTP_UNBALANCED: ledger entry % is off by %', NEW.entry_id, diff USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql;
                CREATE CONSTRAINT TRIGGER ledger_lines_balanced AFTER INSERT ON ledger_lines
                    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION sfmtp_ledger_entry_balanced();
            SQL);
        }

        Ddl::appendOnly('ledger_entries');
        Ddl::appendOnly('ledger_lines');
        foreach (['ledger_accounts', 'ledger_sequences', 'ledger_entries', 'ledger_lines'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_lines');
        Schema::table('ledger_entries', fn (Blueprint $table) => $table->dropForeign('ledger_entries_reverses_fk'));
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('ledger_sequences');
        if (Ddl::isPgsql()) {
            DB::unprepared('DROP FUNCTION IF EXISTS sfmtp_ledger_entry_balanced() CASCADE');
        }
    }
};
