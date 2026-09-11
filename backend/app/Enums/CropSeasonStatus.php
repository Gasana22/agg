<?php

namespace App\Enums;

enum CropSeasonStatus: string
{
    case Planning = 'planning';
    case Nursery = 'nursery';
    case Field = 'field';
    case Monitoring = 'monitoring';
    case Harvested = 'harvested';
    case Closed = 'closed';
}
