<?php

namespace App\Http\Controllers\Api\Traceability;

use App\Enums\TraceBatchStatus;
use App\Enums\TraceEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Traceability\StoreTraceEventRequest;
use App\Http\Resources\TraceEventResource;
use App\Models\TraceBatch;
use App\Models\TraceEvent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class TraceEventController extends Controller
{
    public function index(TraceBatch $traceBatch): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [TraceEvent::class, $traceBatch]);

        return TraceEventResource::collection(
            $traceBatch->events()->with('recorder')->orderBy('date')->get()
        );
    }

    /**
     * sold and recalled are terminal — a batch's chain of custody stops
     * being added to once it reaches either, since both represent the
     * product having left the traceable supply chain.
     */
    public function store(StoreTraceEventRequest $request, TraceBatch $traceBatch): TraceEventResource
    {
        $this->authorize('create', [TraceEvent::class, $traceBatch]);

        if (in_array($traceBatch->status, [TraceBatchStatus::Sold, TraceBatchStatus::Recalled], true)) {
            throw ValidationException::withMessages([
                'trace_batch' => "This batch is already {$traceBatch->status->value} — its chain of custody is closed.",
            ]);
        }

        $event = $traceBatch->events()->create([
            ...$request->validated(),
            'recorded_by' => $request->user()->id,
        ]);

        if ($event->type === TraceEventType::Sold) {
            $traceBatch->update(['status' => TraceBatchStatus::Sold->value]);
        } elseif ($event->type === TraceEventType::Recalled) {
            $traceBatch->update(['status' => TraceBatchStatus::Recalled->value]);
        }

        return new TraceEventResource($event->load('recorder'));
    }
}
