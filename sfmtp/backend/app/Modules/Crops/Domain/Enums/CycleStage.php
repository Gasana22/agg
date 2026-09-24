<?php

namespace App\Modules\Crops\Domain\Enums;

enum CycleStage: string
{
    case Nursery = 'nursery';
    case Planted = 'planted';
    case Growing = 'growing';
    case Harvesting = 'harvesting';
    case Closed = 'closed';

    /** The next stages a cycle may move to by hand (closing is its own action). */
    public function next(): array
    {
        return match ($this) {
            self::Nursery => [self::Planted],
            self::Planted => [self::Growing, self::Harvesting],
            self::Growing => [self::Harvesting],
            self::Harvesting, self::Closed => [],
        };
    }

    /** Stages in which the crop is in the ground. */
    public static function inField(): array
    {
        return [self::Planted->value, self::Growing->value, self::Harvesting->value];
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
