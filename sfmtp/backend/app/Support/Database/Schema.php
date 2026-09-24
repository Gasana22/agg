<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Engine-specific DDL used by migrations: append-only triggers and
 * PostgreSQL row-level security. See docs/02-tenant-isolation.md §6.
 */
class Schema
{
    public static function isPgsql(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public static function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    /**
     * Add a named CHECK constraint (PostgreSQL, and MySQL 8.0.16+).
     */
    public static function check(string $table, string $name, string $expression): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    public static function dropCheck(string $table, string $name): void
    {
        DB::statement(self::isMysql() ? "ALTER TABLE {$table} DROP CHECK {$name}" : "ALTER TABLE {$table} DROP CONSTRAINT {$name}");
    }

    /**
     * CHECK that a column holds one of the given values (mirrors a PHP enum).
     *
     * @param  array<int,string>  $values
     */
    public static function checkIn(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'".str_replace("'", "''", $v)."'", $values));
        self::check($table, "{$table}_{$column}_check", "{$column} IN ({$list})");
    }

    /**
     * Reject UPDATE and/or DELETE on a table at the database level.
     *
     * @param  array<int,string>  $operations  subset of ['UPDATE', 'DELETE']
     */
    public static function appendOnly(string $table, array $operations = ['UPDATE', 'DELETE']): void
    {
        $marker = AppendOnlyViolation::MARKER;

        if (self::isPgsql()) {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION sfmtp_reject_mutation() RETURNS trigger AS \$\$
                BEGIN
                    RAISE EXCEPTION '{$marker}: % on % is not allowed', TG_OP, TG_TABLE_NAME
                        USING ERRCODE = 'P0001';
                END;
                \$\$ LANGUAGE plpgsql;
            SQL);
            $ops = implode(' OR ', $operations);
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE {$ops} ON {$table} FOR EACH ROW EXECUTE FUNCTION sfmtp_reject_mutation();");

            return;
        }

        if (self::isMysql()) {
            foreach ($operations as $op) {
                $name = $table.'_no_'.strtolower($op);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$op} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$marker}: {$op} on {$table} is not allowed';");
            }
        }
    }

    /**
     * Enable tenant row-level security on a farm-owned table (PostgreSQL only).
     *
     * Rows are visible only when app.farm_id matches the row's farm_id, or when
     * the application has explicitly entered TenantContext::bypass(), which sets
     * app.rls_bypass = 'on'. FORCE makes the policy apply to the table owner too.
     */
    public static function tenantRls(string $table): void
    {
        if (! self::isPgsql()) {
            return;
        }

        $predicate = "(current_setting('app.rls_bypass', true) = 'on' OR farm_id::text = current_setting('app.farm_id', true))";

        DB::unprepared(<<<SQL
            ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
            ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
            CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING {$predicate}
                WITH CHECK {$predicate};
        SQL);
    }
}
