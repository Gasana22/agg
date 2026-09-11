<?php

namespace App\Enums;

enum TraceBatchStatus: string
{
    case Active = 'active';
    case Sold = 'sold';
    case Recalled = 'recalled';
}
