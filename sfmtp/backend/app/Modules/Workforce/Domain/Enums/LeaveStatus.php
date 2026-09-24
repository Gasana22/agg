<?php

namespace App\Modules\Workforce\Domain\Enums;

/** Leave requests are decided by someone holding leave.approve. */
enum LeaveStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
