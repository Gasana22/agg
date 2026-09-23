<?php

namespace App\Modules\Traceability\Http\Controllers;

use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Modules\Traceability\Domain\Models\TraceEvent;
use App\Modules\Traceability\Http\Requests\RecordEventRequest;
use App\Modules\Traceability\Http\Resources\TraceEventResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EventController
{
    public function __construct(private readonly Recorder $recorder) {}

    public function index(Request $request, string $farm, TraceBatch $batch): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return TraceEventResource::collection(
            TraceEvent::where('batch_id', $batch->id)
                ->orderBy('occurred_at')
                ->orderBy('farm_seq')
                ->cursorPaginate((int) $request->query('per_page', 50))
        );
    }

    public function store(RecordEventRequest $request, string $farm, TraceBatch $batch): JsonResponse
    {
        $event = $this->recorder->record($batch, $request->validated('event_type'), $request->toEventData());

        return (new TraceEventResource($event->refresh()))->response()->setStatusCode(201);
    }

    public function correct(Request $request, string $farm, TraceEvent $event): JsonResponse
    {
        $data = $request->validate([
            'payload' => ['required', 'array'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $correction = $this->recorder->correct($event, $data['payload'], $data['reason']);

        return (new TraceEventResource($correction->refresh()))->response()->setStatusCode(201);
    }
}
