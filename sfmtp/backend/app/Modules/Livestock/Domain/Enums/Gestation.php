<?php

namespace App\Modules\Livestock\Domain\Enums;

/** Typical gestation lengths, used for expected due dates (species catalogue codes). */
final class Gestation
{
    private const DAYS = ['cattle' => 283, 'goat' => 150, 'sheep' => 147, 'pig' => 114, 'rabbit' => 31];

    public static function days(?string $speciesCode): ?int
    {
        return self::DAYS[$speciesCode] ?? null;
    }
}
