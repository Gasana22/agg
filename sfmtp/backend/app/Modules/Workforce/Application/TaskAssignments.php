<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Access\Contracts\Assignments;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Models\Activity;
use Illuminate\Support\Facades\DB;

/**
 * The `assigned` scope from the member's tasks: an open task on a crop
 * cycle lets a field worker record work on that cycle (and its plot); on an
 * animal group, on its animals. Lists also include tasks from the last
 * 30 days, so recent work stays visible.
 */
class TaskAssignments implements Assignments
{
    public function __construct(private readonly WorkforceAccess $access) {}

    public function subjectIds(string $subjectType, bool $openOnly = true): array
    {
        $worker = $this->access->currentWorker();
        if (! $worker) {
            return [];
        }

        $column = match ($subjectType) {
            'plot' => 'plot_id',
            'location' => 'location_id',
            default => 'subject_id',
        };

        return Activity::query()
            ->when($column === 'subject_id', fn ($q) => $q->where('subject_type', $subjectType))
            ->whereNotNull($column)
            ->whereIn('id', DB::table('worker_tasks')->select('activity_id')
                ->where('farm_id', $worker->farm_id)
                ->where('worker_id', $worker->id)
                ->when($openOnly,
                    fn ($q) => $q->whereIn('status', TaskStatus::OPEN),
                    fn ($q) => $q->where(fn ($q) => $q->whereIn('status', TaskStatus::OPEN)->orWhere('updated_at', '>=', now()->subDays(30)))))
            ->distinct()
            ->pluck($column)
            ->all();
    }
}
