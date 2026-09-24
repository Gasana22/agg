<?php

namespace App\Modules\Sync;

use App\Modules\Sync\Application\ChangeFeed;
use App\Modules\Workforce\Domain\Models\Activity;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\Worker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class SyncServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // docs/06 §1: sync push 30 / min per device (per user without one).
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(30)
            ->by('sync:'.($request->attributes->get('device_id') ?? $request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Every change to a mirrored record goes into the farm's change feed.
        $feed = fn () => $this->app->make(ChangeFeed::class);
        Task::saved(fn (Task $t) => $feed()->touch('tasks', $t->id, $t->farm_id));
        Attendance::saved(fn (Attendance $a) => $feed()->touch('attendance', $a->id, $a->farm_id));
        Leave::saved(fn (Leave $l) => $feed()->touch('leave', $l->id, $l->farm_id));
        Worker::saved(fn (Worker $w) => $feed()->touch('workers', $w->id, $w->farm_id));
        // Tasks carry their activity's title, instructions and status.
        Activity::saved(function (Activity $a) use ($feed) {
            foreach (DB::table('worker_tasks')->where('farm_id', $a->farm_id)->where('activity_id', $a->id)->pluck('id') as $taskId) {
                $feed()->touch('tasks', $taskId, $a->farm_id);
            }
        });
    }
}
