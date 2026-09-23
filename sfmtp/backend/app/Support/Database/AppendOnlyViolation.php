<?php

namespace App\Support\Database;

use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Raised (by the database) when code tries to UPDATE or DELETE a row in an
 * append-only table. The trigger message always contains the marker below.
 */
class AppendOnlyViolation extends RuntimeException
{
    public const MARKER = 'SFMTP_APPEND_ONLY';

    public static function matches(QueryException $e): bool
    {
        return str_contains($e->getMessage(), self::MARKER);
    }
}
