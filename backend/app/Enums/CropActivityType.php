<?php

namespace App\Enums;

enum CropActivityType: string
{
    case Planting = 'planting';
    case Weeding = 'weeding';
    case Irrigation = 'irrigation';
    case Spraying = 'spraying';
    case FertilizerApplication = 'fertilizer_application';
    case Other = 'other';
}
