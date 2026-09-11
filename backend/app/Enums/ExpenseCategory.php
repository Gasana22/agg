<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Input = 'input';
    case Maintenance = 'maintenance';
    case Utilities = 'utilities';
    case Rent = 'rent';
    case Other = 'other';
}
