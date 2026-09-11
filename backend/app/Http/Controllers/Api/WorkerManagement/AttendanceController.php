<?php

namespace App\Http\Controllers\Api\WorkerManagement;

use App\Enums\FarmRole;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkerManagement\ApproveAttendanceRequest;
use App\Http\Requests\WorkerManagement\CheckInRequest;
use App\Http\Requests\WorkerManagement\CheckOutRequest;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Notification;
use App\Models\WorkerProfile;
use App\Support\Geo;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function index(WorkerProfile $workerProfile): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Attendance::class, $workerProfile]);

        return AttendanceResource::collection(
            $workerProfile->attendances()->with('approver')->latest('date')->get()
        );
    }

    public function show(Attendance $attendance): AttendanceResource
    {
        $this->authorize('view', $attendance);

        return new AttendanceResource($attendance->load(['workerProfile.user', 'approver']));
    }

    public function checkIn(CheckInRequest $request, WorkerProfile $workerProfile): AttendanceResource
    {
        $this->authorize('checkInOut', $workerProfile);

        $today = now()->toDateString();

        if ($workerProfile->attendances()->where('date', $today)->exists()) {
            throw ValidationException::withMessages([
                'date' => 'You have already checked in today.',
            ]);
        }

        $this->guardGeofence($workerProfile, (float) $request->validated('gps_lat'), (float) $request->validated('gps_lng'));

        $photoPath = $request->file('photo')->store('attendance-photos', 'public');

        $attendance = $workerProfile->attendances()->create([
            'date' => $today,
            'check_in_at' => now(),
            'check_in_lat' => $request->validated('gps_lat'),
            'check_in_lng' => $request->validated('gps_lng'),
            'check_in_photo_path' => $photoPath,
            'status' => 'pending',
        ]);

        $this->notifyApprovers($workerProfile, $attendance);

        return new AttendanceResource($attendance);
    }

    public function checkOut(CheckOutRequest $request, WorkerProfile $workerProfile): AttendanceResource
    {
        $this->authorize('checkInOut', $workerProfile);

        $today = now()->toDateString();
        $attendance = $workerProfile->attendances()->where('date', $today)->first();

        if (! $attendance) {
            throw ValidationException::withMessages([
                'date' => 'You must check in before you can check out.',
            ]);
        }

        if ($attendance->check_out_at) {
            throw ValidationException::withMessages([
                'date' => 'You have already checked out today.',
            ]);
        }

        $photoPath = $request->file('photo')->store('attendance-photos', 'public');

        $attendance->update([
            'check_out_at' => now(),
            'check_out_lat' => $request->validated('gps_lat'),
            'check_out_lng' => $request->validated('gps_lng'),
            'check_out_photo_path' => $photoPath,
        ]);

        return new AttendanceResource($attendance);
    }

    public function approve(ApproveAttendanceRequest $request, Attendance $attendance): AttendanceResource
    {
        $this->authorize('approve', $attendance);

        $attendance->update([
            'status' => $request->validated('status'),
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'notes' => $request->validated('notes'),
        ]);

        return new AttendanceResource($attendance->load('approver'));
    }

    /**
     * Rejects a check-in whose GPS point is too far from the farm's own
     * reference point. Only enforced when the farm has one set — there is
     * nothing to geofence against otherwise.
     */
    private function guardGeofence(WorkerProfile $workerProfile, float $lat, float $lng): void
    {
        $farm = $workerProfile->farm;

        if ($farm->gps_lat === null || $farm->gps_lng === null) {
            return;
        }

        $distance = Geo::distanceInMeters((float) $farm->gps_lat, (float) $farm->gps_lng, $lat, $lng);
        $radius = config('attendance.geofence_radius_meters');

        if ($distance > $radius) {
            throw ValidationException::withMessages([
                'gps_lat' => sprintf(
                    'You are %.0fm from %s, outside the %dm check-in radius.',
                    $distance,
                    $farm->name,
                    $radius,
                ),
            ]);
        }
    }

    /**
     * Notifies the farm's managers, plus the worker's specific supervisor
     * if one is set and isn't already a manager — mirroring exactly who
     * AttendancePolicy::approve() would let approve this record.
     */
    private function notifyApprovers(WorkerProfile $workerProfile, Attendance $attendance): void
    {
        $workerName = $workerProfile->user?->name ?? 'A worker';

        Notification::sendToFarmRoles(
            $workerProfile->farm,
            [FarmRole::FarmOwner, FarmRole::FarmManager],
            NotificationType::AttendancePendingApproval,
            "{$workerName} checked in — approval needed",
            'Review and approve or reject today\'s check-in.',
            $attendance,
        );

        $supervisor = $workerProfile->supervisor;

        if ($supervisor && ! $supervisor->canManageFarm($workerProfile->farm)) {
            Notification::send(
                $supervisor,
                $workerProfile->farm,
                NotificationType::AttendancePendingApproval,
                "{$workerName} checked in — approval needed",
                'Review and approve or reject today\'s check-in.',
                $attendance,
            );
        }
    }
}
