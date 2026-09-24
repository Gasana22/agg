<?php

namespace App\Support\Database;

/** Append-only rows: the model refuses updates and deletes, like the table's triggers. */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }
}
