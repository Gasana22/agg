<?php

namespace App\Support\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Optimistic concurrency for PATCH (docs/06 §1 "Writes"): the client sends
 * the version it last read as `If-Match: "<version>"` or `version` in the
 * body. A stale version is a 409; no version means "last write wins".
 */
final class OptimisticLock
{
    public static function check(Request $request, Model $model): void
    {
        $expected = $request->input('version') ?? trim((string) $request->header('If-Match'), ' "W/');

        if ($expected === null || $expected === '' || $expected === '*') {
            return;
        }

        if ((string) $expected !== (string) $model->getAttribute('version')) {
            throw ApiException::conflict('version_conflict', 'This record was changed by someone else. Reload it and try again.');
        }
    }
}
