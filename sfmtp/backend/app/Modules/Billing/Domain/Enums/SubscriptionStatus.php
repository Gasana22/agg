<?php

namespace App\Modules\Billing\Domain\Enums;

/**
 * trialing → active ⇄ grace → suspended; cancelled at any point.
 * `grace` is the requirements' "past due": access continues with a warning.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function allowsAccess(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::Grace], true);
    }
}
