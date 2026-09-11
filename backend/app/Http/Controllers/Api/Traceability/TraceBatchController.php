<?php

namespace App\Http\Controllers\Api\Traceability;

use App\Enums\TraceEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Traceability\StoreTraceBatchRequest;
use App\Http\Resources\TraceBatchResource;
use App\Models\AnimalProductionRecord;
use App\Models\CropHarvest;
use App\Models\Farm;
use App\Models\TraceBatch;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TraceBatchController extends Controller
{
    private const WITH = ['creator', 'events.recorder'];

    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [TraceBatch::class, $farm]);

        return TraceBatchResource::collection(
            $farm->traceBatches()->with(self::WITH)->latest()->get()
        );
    }

    /**
     * Registers a batch from an already-recorded harvest or production
     * collection. The batch snapshots product_name/quantity/unit from that
     * source at creation time and auto-creates the first chain-of-custody
     * event (produced); nothing else can create a "produced" event later.
     */
    public function store(StoreTraceBatchRequest $request, Farm $farm): TraceBatchResource
    {
        $this->authorize('create', [TraceBatch::class, $farm]);

        [$source, $producedDate, $productName] = $request->validated('source_type') === 'crop_harvest'
            ? $this->resolveCropHarvest($request->validated('source_id'), $farm, $request->user())
            : $this->resolveAnimalProductionRecord($request->validated('source_id'), $farm, $request->user());

        if (TraceBatch::where('traceable_type', $source::class)->where('traceable_id', $source->id)->exists()) {
            throw ValidationException::withMessages([
                'source_id' => 'A trace batch already exists for this record.',
            ]);
        }

        $batch = DB::transaction(function () use ($source, $farm, $producedDate, $productName, $request) {
            $batch = TraceBatch::create([
                'farm_id' => $farm->id,
                'code' => TraceBatch::generateCode(),
                'traceable_type' => $source::class,
                'traceable_id' => $source->id,
                'product_name' => $productName,
                'quantity' => $source->quantity,
                'unit' => $source->unit,
                'status' => 'active',
                'created_by' => $request->user()->id,
            ]);

            $batch->events()->create([
                'type' => TraceEventType::Produced->value,
                'date' => $producedDate,
                'recorded_by' => $request->user()->id,
            ]);

            return $batch;
        });

        return new TraceBatchResource($batch->load(self::WITH));
    }

    public function show(TraceBatch $traceBatch): TraceBatchResource
    {
        $this->authorize('view', $traceBatch);

        return new TraceBatchResource($traceBatch->load(self::WITH));
    }

    /**
     * @return array{0: CropHarvest, 1: Carbon, 2: string}
     */
    private function resolveCropHarvest(int $id, Farm $farm, User $user): array
    {
        $harvest = CropHarvest::with('cropSeason.crop')->find($id);

        if (! $harvest || $harvest->cropSeason->farm_id !== $farm->id) {
            throw ValidationException::withMessages([
                'source_id' => 'No crop harvest with that id was found on this farm.',
            ]);
        }

        abort_unless($user->canManageCrops($farm), 403);

        return [$harvest, $harvest->harvest_date, $harvest->cropSeason->crop->name];
    }

    /**
     * @return array{0: AnimalProductionRecord, 1: Carbon, 2: string}
     */
    private function resolveAnimalProductionRecord(int $id, Farm $farm, User $user): array
    {
        $record = AnimalProductionRecord::with('animal')->find($id);

        if (! $record || $record->animal->farm_id !== $farm->id) {
            throw ValidationException::withMessages([
                'source_id' => 'No production record with that id was found on this farm.',
            ]);
        }

        abort_unless($user->canManageLivestock($farm), 403);

        return [$record, $record->date, $record->product_type];
    }
}
