<?php

namespace App\Modules\Traceability\Http\Controllers;

use App\Modules\Traceability\Application\JourneyProjector;
use App\Modules\Traceability\Application\JourneyService;
use App\Modules\Traceability\Application\JourneyViews;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Http\Resources\TraceBatchResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The journey of a batch and the views of docs/07 §6. The full journey is
 * served from the `product_journeys` projection; `?fresh=true`, a depth or
 * a single direction walk the graph live.
 */
class JourneyController
{
    public function __construct(private readonly JourneyViews $views) {}

    public function show(Request $request, string $farm, TraceBatch $batch, JourneyService $journeys, JourneyProjector $projector): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['sometimes', 'in:backward,forward,both'],
            'depth' => ['sometimes', 'integer', 'min:1', 'max:'.JourneyService::MAX_DEPTH],
            'fresh' => ['sometimes', 'boolean'],
        ]);
        $direction = $data['direction'] ?? 'both';
        $live = $request->boolean('fresh') || isset($data['depth']) || $direction !== 'both';

        if (! $live) {
            $row = DB::table('product_journeys')->where('batch_id', $batch->id)->first();
            $projected = $row ? [
                'upstream' => json_decode($row->upstream, true),
                'downstream' => json_decode($row->downstream, true),
                'summary' => json_decode($row->summary, true),
                'refreshed_at' => CarbonImmutable::parse($row->refreshed_at),
            ] : null;
            if ($projected === null) {
                // Not projected yet (older data): build it now.
                $projector->project($batch);

                return $this->show($request, $farm, $batch, $journeys, $projector);
            }

            return $this->respond($batch, $projected['upstream'], $projected['downstream'], $projected['summary'], [
                'source' => 'projection', 'refreshed_at' => $projected['refreshed_at']->toIso8601ZuluString(),
            ]);
        }

        $depth = (int) ($data['depth'] ?? JourneyService::MAX_DEPTH);
        $backward = $direction === 'forward' ? null : $journeys->walk($batch, 'backward', $depth);
        $forward = $direction === 'backward' ? null : $journeys->walk($batch, 'forward', $depth);
        $lineage = array_merge([$batch->id], array_column($backward['nodes'] ?? [], 'id'));
        $summary = $this->views->evidence($lineage) + [
            'sources' => $backward === null ? null : count($backward['nodes']),
            'destinations' => $forward === null ? null : count($forward['nodes']),
        ];

        return $this->respond($batch, $backward, $forward, $summary, ['source' => 'live', 'refreshed_at' => now()->toIso8601ZuluString()]);
    }

    public function timeline(Request $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $data = $request->validate(['direction' => ['sometimes', 'in:backward,forward,both']]);
        $timeline = $this->views->timeline($batch, $data['direction'] ?? 'backward');

        return new JsonResponse(['data' => $timeline['events'], 'meta' => ['truncated' => $timeline['truncated'], 'limit' => JourneyViews::TIMELINE_LIMIT]]);
    }

    public function workers(string $farm, TraceBatch $batch): JsonResponse
    {
        return new JsonResponse(['data' => $this->views->workers($batch)]);
    }

    public function inputs(string $farm, TraceBatch $batch): JsonResponse
    {
        return new JsonResponse(['data' => $this->views->inputs($batch)]);
    }

    public function sales(string $farm, TraceBatch $batch): JsonResponse
    {
        return new JsonResponse(['data' => $this->views->sales($batch)]);
    }

    public function locations(string $farm, TraceBatch $batch): JsonResponse
    {
        return new JsonResponse(['data' => $this->views->locations($batch)]);
    }

    private function respond(TraceBatch $batch, ?array $backward, ?array $forward, array $summary, array $projection): JsonResponse
    {
        return new JsonResponse(['data' => [
            'batch' => new TraceBatchResource($batch),
            'backward' => $backward,
            'forward' => $forward,
            'evidence_summary' => $summary,
            'projection' => $projection,
        ]]);
    }
}
