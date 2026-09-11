<?php

namespace App\Enums;

enum AnimalHealthLogType: string
{
    case Vaccination = 'vaccination';
    case Feeding = 'feeding';
    case Weight = 'weight';
    case Treatment = 'treatment';
}
