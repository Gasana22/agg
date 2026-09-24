<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Traceability\Contracts\WorkerNames;
use App\Modules\Workforce\Domain\Models\Worker;

class TraceWorkerNames implements WorkerNames
{
    public function describe(array $workerIds): array
    {
        return Worker::whereIn('id', $workerIds)->get(['id', 'worker_code', 'full_name'])
            ->mapWithKeys(fn (Worker $w) => [$w->id => ['code' => $w->worker_code, 'name' => $w->full_name]])
            ->all();
    }
}
