<?php

namespace App\Modules\Platform\Application;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/** Platform health for the admin dashboard and /admin/system/health. */
class SystemHealth
{
    /** @return array<string, array{status:string, latency_ms?:float, detail?:mixed}> */
    public function checks(): array
    {
        return [
            'database' => $this->probe(fn () => DB::select('select 1'), fn () => DB::connection()->getDriverName()),
            'cache' => $this->probe(fn () => Cache::put('health:ping', 1, 10), fn () => config('cache.default')),
            'queue' => $this->probe(fn () => null, fn () => [
                'connection' => config('queue.default'),
                'pending' => $this->safe(fn () => Queue::size()),
                'failed' => $this->safe(fn () => DB::table('failed_jobs')->count()),
            ]),
            'storage' => $this->probe(fn () => null, fn () => [
                'free_bytes' => $this->safe(fn () => (int) disk_free_space(storage_path())),
                'total_bytes' => $this->safe(fn () => (int) disk_total_space(storage_path())),
            ]),
        ];
    }

    /** @return array<string,string> */
    public function versions(): array
    {
        return [
            'app' => (string) config('app.version', env('APP_VERSION', 'dev')),
            'php' => PHP_VERSION,
            'laravel' => Application::VERSION,
        ];
    }

    private function probe(callable $check, callable $detail): array
    {
        $start = hrtime(true);
        try {
            $check();
            $status = 'ok';
        } catch (Throwable) {
            $status = 'down';
        }

        return [
            'status' => $status,
            'latency_ms' => round((hrtime(true) - $start) / 1e6, 1),
            'detail' => $this->safe($detail),
        ];
    }

    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
