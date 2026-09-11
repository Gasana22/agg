<?php

namespace App\Enums;

enum PoultryFlockStatus: string
{
    case Active = 'active';
    case Sold = 'sold';
    case Closed = 'closed';
}
