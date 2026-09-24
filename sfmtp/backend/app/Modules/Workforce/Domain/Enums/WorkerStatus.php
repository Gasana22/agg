<?php

namespace App\Modules\Workforce\Domain\Enums;

/** Inactive workers keep their history but get no new tasks. */
enum WorkerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
