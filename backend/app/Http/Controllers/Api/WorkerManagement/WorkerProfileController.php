<?php

namespace App\Http\Controllers\Api\WorkerManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkerManagement\StoreWorkerProfileRequest;
use App\Http\Requests\WorkerManagement\UpdateWorkerProfileRequest;
use App\Http\Resources\WorkerProfileResource;
use App\Models\Farm;
use App\Models\WorkerProfile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class WorkerProfileController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [WorkerProfile::class, $farm]);

        return WorkerProfileResource::collection(
            $farm->workerProfiles()->with(['user', 'supervisor'])->get()
        );
    }

    public function store(StoreWorkerProfileRequest $request, Farm $farm): WorkerProfileResource
    {
        $this->authorize('create', [WorkerProfile::class, $farm]);

        $userId = $request->validated('user_id');

        if (! $farm->users()->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'This user must be added as a farm member before creating a worker profile for them.',
            ]);
        }

        $profile = $farm->workerProfiles()->create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        return new WorkerProfileResource($profile->load(['user', 'supervisor']));
    }

    public function show(WorkerProfile $workerProfile): WorkerProfileResource
    {
        $this->authorize('view', $workerProfile);

        return new WorkerProfileResource($workerProfile->load(['user', 'supervisor']));
    }

    public function update(UpdateWorkerProfileRequest $request, WorkerProfile $workerProfile): WorkerProfileResource
    {
        $this->authorize('update', $workerProfile);

        $workerProfile->update($request->validated());

        return new WorkerProfileResource($workerProfile->load(['user', 'supervisor']));
    }

    public function destroy(WorkerProfile $workerProfile): Response
    {
        $this->authorize('delete', $workerProfile);

        $workerProfile->delete();

        return response()->noContent();
    }
}
