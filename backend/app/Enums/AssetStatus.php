<?php

namespace App\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case UnderMaintenance = 'under_maintenance';
    case Retired = 'retired';
}
