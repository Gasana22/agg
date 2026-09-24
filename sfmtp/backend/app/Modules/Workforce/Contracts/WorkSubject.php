<?php

namespace App\Modules\Workforce\Contracts;

/**
 * What an activity is about, as resolved by the module that owns it.
 * `module` must match the activity type's module (crop work on a crop
 * cycle, animal work on an animal), unless the subject is a plot, a
 * location or general work.
 */
final readonly class WorkSubject
{
    public function __construct(
        public string $type,
        public ?string $id,
        public string $label,
        public ?string $module = null,
        public ?string $plotId = null,
        public ?string $locationId = null,
        public ?string $traceBatchId = null,
        public bool $active = true,
    ) {}
}
