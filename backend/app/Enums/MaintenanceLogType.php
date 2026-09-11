<?php

namespace App\Enums;

enum MaintenanceLogType: string
{
    case Repair = 'repair';
    case RoutineService = 'routine_service';
    case Inspection = 'inspection';
    case Other = 'other';
}
