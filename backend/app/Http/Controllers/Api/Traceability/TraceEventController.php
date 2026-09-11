<?php

namespace App\Http\Controllers\Api\Traceability;

use App\Enums\FarmRole;
use App\Enums\NotificationType;
use App\Enums\TraceBatchStatus;
use App\Enums\TraceEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Traceability\StoreTraceEventRequest;
use App\Http\Resources\TraceEventResource;
use App\Models\AnimalProductionRecord;
use App\Models\CropHarvest;
use App\Models\Notification;
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
            $this->notifyRecall($traceBatch);
        }

        return new TraceEventResource($event->load('recorder'));
    }

    /**
     * A recall is safety-critical: notify farm management plus whichever
     * domain produced the batch, not just whoever happens to be watching.
     */
    private function notifyRecall(TraceBatch $traceBatch): void
    {
        $roles = [FarmRole::FarmOwner, FarmRole::FarmManager];

        $roles[] = match ($traceBatch->traceable_type) {
            CropHarvest::class => FarmRole::Agronomist,
            AnimalProductionRecord::class => FarmRole::LivestockManager,
            default => null,
        };

        Notification::sendToFarmRoles(
            $traceBatch->farm,
            array_filter($roles),
            NotificationType::TraceBatchRecalled,
            "Batch {$traceBatch->code} recalled",
            "{$traceBatch->product_name} ({$traceBatch->quantity} {$traceBatch->unit}) has been recalled.",
            $traceBatch,
        );
    }
}
