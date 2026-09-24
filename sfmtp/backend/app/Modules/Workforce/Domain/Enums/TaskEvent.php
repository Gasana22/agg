<?php

namespace App\Modules\Workforce\Domain\Enums;

/** Events in a task's log. `note` records progress without changing status. */
enum TaskEvent: string
{
    case Start = 'start';
    case Pause = 'pause';
    case Resume = 'resume';
    case Submit = 'submit';
    case Verify = 'verify';
    case Reject = 'reject';
    case Cancel = 'cancel';
    case Note = 'note';

    /** Events the assigned worker performs; the rest belong to supervisors. */
    public const WORKER = ['start', 'pause', 'resume', 'submit', 'note'];

    /** @return array<int, TaskStatus> statuses the event may start from */
    public function allowedFrom(): array
    {
        return match ($this) {
            self::Start => [TaskStatus::Assigned, TaskStatus::Rejected],
            self::Pause => [TaskStatus::InProgress],
            self::Resume => [TaskStatus::Paused],
            self::Submit => [TaskStatus::InProgress, TaskStatus::Paused],
            self::Verify, self::Reject => [TaskStatus::Submitted],
            self::Cancel => [TaskStatus::Assigned, TaskStatus::InProgress, TaskStatus::Paused, TaskStatus::Rejected],
            self::Note => [TaskStatus::Assigned, TaskStatus::InProgress, TaskStatus::Paused, TaskStatus::Submitted, TaskStatus::Rejected],
        };
    }

    public function to(): ?TaskStatus
    {
        return match ($this) {
            self::Start, self::Resume => TaskStatus::InProgress,
            self::Pause => TaskStatus::Paused,
            self::Submit => TaskStatus::Submitted,
            self::Verify => TaskStatus::Verified,
            self::Reject => TaskStatus::Rejected,
            self::Cancel => TaskStatus::Cancelled,
            self::Note => null,
        };
    }

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
