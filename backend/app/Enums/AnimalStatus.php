<?php

namespace App\Enums;

enum AnimalStatus: string
{
    case Active = 'active';
    case Sold = 'sold';
    case Deceased = 'deceased';
}
