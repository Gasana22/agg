<?php

namespace App\Modules\Access\Domain\Enums;

/**
 * How far a grant reaches (docs/04-roles-and-permissions.md §1).
 */
enum PermissionScope: string
{
    case All = 'all';            // every record in the farm
    case Assigned = 'assigned';  // records the member is assigned to
    case Own = 'own';            // records the member created / that are theirs

    public function rank(): int
    {
        return match ($this) {
            self::All => 3,
            self::Assigned => 2,
            self::Own => 1,
        };
    }

    public function widest(self $other): self
    {
        return $this->rank() >= $other->rank() ? $this : $other;
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
