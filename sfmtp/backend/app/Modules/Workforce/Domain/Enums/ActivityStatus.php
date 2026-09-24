<?php

namespace App\Modules\Workforce\Domain\Enums;

/** An activity is completed when every task on it is verified or cancelled. */
enum ActivityStatus: string
{
    case Open = 'open';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
