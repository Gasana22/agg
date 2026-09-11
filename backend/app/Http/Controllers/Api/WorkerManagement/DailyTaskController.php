<?php

namespace App\Http\Controllers\Api\WorkerManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkerManagement\StoreDailyTaskRequest;
use App\Http\Requests\WorkerManagement\UpdateDailyTaskRequest;
use App\Http\Requests\WorkerManagement\UpdateDailyTaskStatusRequest;
use App\Http\Resources\DailyTaskResource;
use App\Models\DailyTask;
use App\Models\Farm;
use App\Models\WorkerProfile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class DailyTaskController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [DailyTask::class, $farm]);

        return DailyTaskResource::collection(
            $farm->dailyTasks()->with(['assignee', 'assigner'])->latest()->get()
        );
    }

    public function store(StoreDailyTaskRequest $request, Farm $farm): DailyTaskResource
    {
        $assignedTo = $request->validated('assigned_to');

        if (! $farm->users()->where('user_id', $assignedTo)->exists()) {
            throw ValidationException::withMessages([
                'assigned_to' => 'This user must be a member of the farm to be assigned a task.',
            ]);
        }

        $assigneeProfile = WorkerProfile::where('farm_id', $farm->id)
            ->where('user_id', $assignedTo)
            ->first();

        $this->authorize('create', [DailyTask::class, $farm, $assigneeProfile]);

        $task = $farm->dailyTasks()->create([
            ...$request->validated(),
            'assigned_by' => $request->user()->id,
            'status' => 'pending',
        ]);

        return new DailyTaskResource($task->load(['assignee', 'assigner']));
    }

    public function show(DailyTask $dailyTask): DailyTaskResource
    {
        $this->authorize('view', $dailyTask);

        return new DailyTaskResource($dailyTask->load(['assignee', 'assigner']));
    }

    public function update(UpdateDailyTaskRequest $request, DailyTask $dailyTask): DailyTaskResource
    {
        $this->authorize('update', $dailyTask);

        $dailyTask->update($request->validated());

        return new DailyTaskResource($dailyTask->load(['assignee', 'assigner']));
    }

    public function updateStatus(UpdateDailyTaskStatusRequest $request, DailyTask $dailyTask): DailyTaskResource
    {
        $this->authorize('updateStatus', $dailyTask);

        $status = $request->validated('status');
        $data = $request->safe()->except(['photo']);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('daily-task-photos', 'public');
        }

        $dailyTask->update([
            ...$data,
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);

        return new DailyTaskResource($dailyTask->load(['assignee', 'assigner']));
    }

    public function destroy(DailyTask $dailyTask): Response
    {
        $this->authorize('delete', $dailyTask);

        $dailyTask->delete();

        return response()->noContent();
    }
}
