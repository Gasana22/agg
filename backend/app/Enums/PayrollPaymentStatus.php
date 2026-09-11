<?php

namespace App\Enums;

enum PayrollPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
}
