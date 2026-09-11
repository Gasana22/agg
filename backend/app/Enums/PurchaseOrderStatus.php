<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Ordered = 'ordered';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}
