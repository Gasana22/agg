<?php

namespace App\Support\Database;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * A per-farm counter taken under a row lock, for numbers that must stay
 * unique when documents are created in parallel (lots, stock documents).
 * Call inside a transaction; the lock is held until it commits.
 */
final class Sequence
{
    public static function next(string $name): int
    {
        $farmId = app(TenantContext::class)->farmId();
        $row = fn () => DB::table('farm_sequences')->where('farm_id', $farmId)->where('name', $name)->lockForUpdate()->value('last_value');
        // Lock first; insert only when missing (INSERT IGNORE on an existing key deadlocks on MySQL).
        $last = $row();
        if ($last === null) {
            DB::table('farm_sequences')->insertOrIgnore(['farm_id' => $farmId, 'name' => $name, 'last_value' => 0]);
            $last = $row();
        }
        $last = (int) $last;
        DB::table('farm_sequences')->where('farm_id', $farmId)->where('name', $name)->update(['last_value' => $last + 1]);

        return $last + 1;
    }

    /** "PREFIX-001" style, from the counter. */
    public static function code(string $name, string $prefix, int $pad = 3): string
    {
        return $prefix.'-'.str_pad((string) self::next($name), $pad, '0', STR_PAD_LEFT);
    }
}
