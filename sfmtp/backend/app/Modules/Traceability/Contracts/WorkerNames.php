<?php

namespace App\Modules\Traceability\Contracts;

/**
 * Names for the workers that trace events point to. Workforce provides it
 * (Workforce depends on Traceability, never the other way round).
 */
interface WorkerNames
{
    /**
     * @param  array<int, string>  $workerIds
     * @return array<string, array{code: string, name: string}> keyed by worker id
     */
    public function describe(array $workerIds): array;
}
