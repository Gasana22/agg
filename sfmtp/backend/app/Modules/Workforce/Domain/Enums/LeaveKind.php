<?php

namespace App\Modules\Workforce\Domain\Enums;

/** Kinds of leave a worker can request. */
enum LeaveKind: string
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Compassionate = 'compassionate';
    case Unpaid = 'unpaid';
    case Other = 'other';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
