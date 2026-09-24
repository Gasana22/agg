<?php

namespace App\Modules\Workforce;

use App\Modules\Access\Contracts\Assignments;
use App\Modules\Traceability\Contracts\WorkerNames;
use App\Modules\Workforce\Application\TaskAssignments;
use App\Modules\Workforce\Application\TraceWorkerNames;
use App\Modules\Workforce\Application\WorkSubjects;
use App\Modules\Workforce\Domain\Models\Activity;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Leave;
use App\Modules\Workforce\Domain\Models\Task;
use App\Modules\Workforce\Domain\Models\Worker;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WorkforceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tasks decide the `assigned` scope for crops, livestock and the map.
        $this->app->bind(Assignments::class, TaskAssignments::class);
        $this->app->singleton(WorkSubjects::class);
        $this->app->bind(WorkerNames::class, TraceWorkerNames::class);
    }

    public function boot(): void
    {
        // Bound through the models' farm scope: another farm's id is a 404.
        Route::model('worker', Worker::class);
        Route::model('activity', Activity::class);
        Route::model('task', Task::class);
        Route::model('attendance', Attendance::class);
        Route::model('leave', Leave::class);
    }
}
