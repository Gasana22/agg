<?php

namespace App\Enums;

enum CropMonitoringType: string
{
    case Disease = 'disease';
    case Pest = 'pest';
    case Growth = 'growth';
}
