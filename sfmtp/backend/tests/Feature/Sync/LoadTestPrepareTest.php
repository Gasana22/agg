<?php

namespace Tests\Feature\Sync;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use Tests\TestCase;

class LoadTestPrepareTest extends TestCase
{
    public function test_it_prepares_phones_with_tasks_that_can_sync(): void
    {
        $this->seed(CatalogSeeder::class);
        $out = tempnam(sys_get_temp_dir(), 'plan');
        $this->artisan('sync:loadtest-prepare', ['--devices' => 2, '--tasks' => 2, '--out' => $out])->assertSuccessful();
        $plan = json_decode(file_get_contents($out), true);
        unlink($out);

        $this->assertCount(2, $plan['devices']);
        $this->assertCount(2, $plan['devices'][0]['tasks']);

        // A phone's token pushes a task start through the real sync API.
        $this->app['auth']->forgetGuards();
        $results = $this->withToken($plan['devices'][0]['token'])->withHeader('Idempotency-Key', 'load-0001')
            ->postJson("/api/v1/farms/{$plan['farm_id']}/sync/push", ['mutations' => [[
                'mutation_id' => '01a0e900-0000-7000-8000-000000000001', 'entity' => 'worker_task_logs', 'op' => 'insert',
                'id' => '01a0e900-0000-7000-8000-000000000002', 'occurred_at' => now()->toIso8601String(),
                'data' => ['task_id' => $plan['devices'][0]['tasks'][0], 'event' => 'start', 'lat' => 0.4, 'lng' => 32.4],
            ]]])->assertOk()->json('data.results');
        $this->assertSame('applied', $results[0]['status']);
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('sync:loadtest-prepare', ['--devices' => 1])->assertFailed();
    }
}
