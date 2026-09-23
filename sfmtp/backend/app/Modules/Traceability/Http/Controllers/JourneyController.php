<?php

namespace App\Modules\Traceability\Http\Controllers;

use App\Modules\Traceability\Application\JourneyService;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Modules\Traceability\Http\Resources\TraceBatchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JourneyController
{
    public function show(Request $request, string $farm, TraceBatch $batch, JourneyService $journeys): JsonResponse
    {
        $data = $request->validate([
            'direction' => ['sometimes', 'in:backward,forward,both'],
            'depth' => ['sometimes', 'integer', 'min:1', 'max:'.JourneyService::MAX_DEPTH],
        ]);
        $direction = $data['direction'] ?? 'both';
        $depth = (int) ($data['depth'] ?? JourneyService::MAX_DEPTH);

        return new JsonResponse(['data' => [
            'batch' => new TraceBatchResource($batch),
            'backward' => $direction === 'forward' ? null : $journeys->walk($batch, 'backward', $depth),
            'forward' => $direction === 'backward' ? null : $journeys->walk($batch, 'forward', $depth),
            'evidence_summary' => [
                'events' => TraceEvent::where('batch_id', $batch->id)->count(),
                'gps_points' => TraceEvent::where('batch_id', $batch->id)->whereNotNull('latitude')->count(),
            ],
        ]]);
    }
}
