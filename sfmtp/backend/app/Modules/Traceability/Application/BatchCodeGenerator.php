<?php

namespace App\Modules\Traceability\Application;

use Illuminate\Support\Facades\DB;

/**
 * Public-safe, globally unique batch codes like SFM-7KQ2-9XA4
 * (Crockford base32: no I, L, O, U).
 */
class BatchCodeGenerator
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function generate(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 8; $i++) {
                $raw .= self::ALPHABET[random_int(0, 31)];
            }
            $code = 'SFM-'.substr($raw, 0, 4).'-'.substr($raw, 4);
            // Under row-level security this check only sees the current farm.
            // The global unique index is the real guard (a clash is ~1 in 10^12).
        } while (DB::table('trace_batches')->where('batch_code', $code)->exists());

        return $code;
    }
}
