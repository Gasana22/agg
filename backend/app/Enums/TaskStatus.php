<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Ongoing = 'ongoing';
    case Completed = 'completed';
}
