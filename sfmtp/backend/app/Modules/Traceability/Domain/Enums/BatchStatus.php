<?php

namespace App\Modules\Traceability\Domain\Enums;

enum BatchStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Recalled = 'recalled';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
