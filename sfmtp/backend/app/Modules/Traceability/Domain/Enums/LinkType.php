<?php

namespace App\Modules\Traceability\Domain\Enums;

enum LinkType: string
{
    case Derived = 'derived';
    case Split = 'split';
    case Merge = 'merge';
    case Process = 'process';
    case Package = 'package';
    case Ship = 'ship';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
