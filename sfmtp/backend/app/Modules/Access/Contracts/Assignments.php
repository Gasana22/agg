<?php

namespace App\Modules\Access\Contracts;

/**
 * What the current member is assigned to, for the `assigned` scope
 * (docs/04 §1). Workforce answers from the member's tasks; before a farm
 * has any tasks, a member is assigned to nothing.
 */
interface Assignments
{
    /**
     * Ids of the subjects of a given type (crop_cycle, plot, animal,
     * animal_group, location) that the member's tasks point to.
     * `$openOnly` limits it to tasks still being worked on, which is what
     * recording needs; lists use recent tasks too.
     *
     * @return array<int,string>
     */
    public function subjectIds(string $subjectType, bool $openOnly = true): array;
}
