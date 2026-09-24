<?php

namespace App\Modules\Workforce\Domain\Enums;

/**
 * The task state machine (docs/03 §6):
 *
 *   assigned ─start→ in_progress ⇄ paused (pause / resume)
 *   in_progress | paused ─submit→ submitted ─verify→ verified
 *                                           └reject→ rejected ─start→ in_progress
 *   assigned | in_progress | paused | rejected ─cancel→ cancelled
 */
enum TaskStatus: string
{
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Statuses in which the worker still has something to do. */
    public const OPEN = ['assigned', 'in_progress', 'paused', 'rejected'];

    public function isFinal(): bool
    {
        return in_array($this, [self::Verified, self::Cancelled], true);
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
