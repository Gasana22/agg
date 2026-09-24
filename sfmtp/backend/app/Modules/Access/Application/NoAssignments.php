<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\Assignments;

/** Default until a module that assigns work (Workforce) is loaded. */
final class NoAssignments implements Assignments
{
    public function subjectIds(string $subjectType, bool $openOnly = true): array
    {
        return [];
    }
}
