<?php

namespace App\Modules\Traceability\Application;

use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Jobs\RefreshJourneys;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the `product_journeys` read model current (docs/07 §4).
 *
 * The Recorder touches a batch whenever it writes an event or a link; once
 * the transaction commits, one queued job per farm refreshes the touched
 * batches and everything connected to them. Nothing is queued for a
 * transaction that rolls back. A singleton: it holds only that buffer.
 */
class JourneyProjector
{
    /** @var array<string, array<string, true>> farm id => batch ids waiting */
    private array $pending = [];

    private bool $paused = false;

    /** Bulk loads (the demo seeder) pause refreshing and project once at the end. */
    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function touch(string $farmId, string $batchId): void
    {
        if ($this->paused) {
            return;
        }
        $this->pending[$farmId][$batchId] = true;
        // Each callback flushes whatever is waiting; later ones find nothing.
        DB::afterCommit(fn () => $this->flush());
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $farmId => $ids) {
            RefreshJourneys::dispatch($farmId, array_keys($ids));
        }
    }

    /**
     * Refresh the given batches and every batch connected to them, in the
     * current farm context.
     *
     * @param  array<int, string>  $batchIds
     */
    public function refresh(array $batchIds): int
    {
        $journeys = app(JourneyService::class);
        $all = [];
        foreach ($batchIds as $id) {
            $all[$id] = true;
            foreach (['backward', 'forward'] as $direction) {
                foreach ($journeys->reachableIds($id, $direction) as $other) {
                    $all[$other] = true;
                }
            }
        }

        $count = 0;
        foreach (TraceBatch::whereIn('id', array_keys($all))->get() as $batch) {
            $this->project($batch);
            $count++;
        }

        return $count;
    }

    public function project(TraceBatch $batch): array
    {
        [$journeys, $views] = [app(JourneyService::class), app(JourneyViews::class)];
        $timeline = $views->timeline($batch, 'backward');
        $upstream = $journeys->walk($batch, 'backward');
        $downstream = $journeys->walk($batch, 'forward');
        $lineage = array_merge([$batch->id], array_column($upstream['nodes'], 'id'));
        $row = [
            'upstream' => json_encode($upstream),
            'downstream' => json_encode($downstream),
            'timeline' => json_encode($timeline),
            'summary' => json_encode($views->evidence($lineage) + [
                'sources' => count($upstream['nodes']),
                'destinations' => count($downstream['nodes']),
            ]),
            'last_seq' => (int) collect($timeline['events'])->max('seq'),
            'refreshed_at' => now(),
        ];
        DB::table('product_journeys')->updateOrInsert(['batch_id' => $batch->id], $row + ['farm_id' => $batch->farm_id]);

        return $row;
    }
}
