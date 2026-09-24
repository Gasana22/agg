<?php

namespace App\Modules\Workforce\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Domain\Enums\AttendanceSource;
use App\Modules\Workforce\Domain\Models\Attendance;
use App\Modules\Workforce\Domain\Models\Worker;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Check-in and check-out (one attendance record per worker and day, in the
 * farm's time zone), with an optional location and selfie. Managers holding
 * attendance.approve enter or correct records by hand, with a reason.
 */
class AttendanceBook
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WorkforceAccess $access,
        private readonly TenantContext $context,
    ) {}

    /** @param  array{id?:string, occurred_at?:string, lat?:float, lng?:float, accuracy_m?:float, photo_id?:string, note?:string}  $data */
    public function checkIn(array $data, AttendanceSource $source = AttendanceSource::Mobile): Attendance
    {
        $worker = $this->access->requireWorker();
        $at = $this->time($data['occurred_at'] ?? null);
        $day = $at->setTimezone($this->context->farm()->timezone)->toDateString();
        $this->access->assertOwnMedia($data['photo_id'] ?? null);

        return DB::transaction(function () use ($worker, $data, $at, $day, $source) {
            $existing = Attendance::where('worker_id', $worker->id)->whereDate('work_date', $day)->lockForUpdate()->first();
            if ($existing) {
                if (isset($data['id']) && $existing->id === $data['id']) {
                    return $existing;
                }
                throw new ApiException(409, 'already_checked_in', "You already checked in on {$day}.", ['attendance_id' => $existing->id]);
            }

            $attendance = Attendance::create([
                'id' => $data['id'] ?? null,
                'worker_id' => $worker->id,
                'work_date' => $day,
                'check_in_at' => $at,
                'check_in_lat' => $data['lat'] ?? null,
                'check_in_lng' => $data['lng'] ?? null,
                'check_in_accuracy_m' => $data['accuracy_m'] ?? null,
                'check_in_photo_id' => $data['photo_id'] ?? null,
                'source' => $source,
                'note' => $data['note'] ?? null,
                'recorded_by' => Auth::id(),
            ]);

            return $attendance->refresh();
        });
    }

    /** Close the worker's open day. */
    public function checkOut(array $data): Attendance
    {
        $worker = $this->access->requireWorker();
        $at = $this->time($data['occurred_at'] ?? null);
        $this->access->assertOwnMedia($data['photo_id'] ?? null);

        return DB::transaction(function () use ($worker, $data, $at) {
            $open = Attendance::where('worker_id', $worker->id)->whereNull('check_out_at')
                ->when(isset($data['attendance_id']), fn ($q) => $q->whereKey($data['attendance_id']))
                ->orderByDesc('check_in_at')->lockForUpdate()->first()
                ?? throw ApiException::conflict('not_checked_in', 'You are not checked in.');
            if ($at->lessThan($open->check_in_at)) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['occurred_at' => ['Check-out cannot be before check-in.']]);
            }
            $open->forceFill([
                'check_out_at' => $at,
                'check_out_lat' => $data['lat'] ?? null,
                'check_out_lng' => $data['lng'] ?? null,
                'check_out_accuracy_m' => $data['accuracy_m'] ?? null,
                'check_out_photo_id' => $data['photo_id'] ?? null,
            ])->save();

            return $open;
        });
    }

    /** A manager's entry for a worker who could not check in (no phone, no signal). */
    public function enter(Worker $worker, array $data): Attendance
    {
        $in = CarbonImmutable::parse($data['check_in_at'])->utc();
        $out = isset($data['check_out_at']) ? CarbonImmutable::parse($data['check_out_at'])->utc() : null;
        $this->assertTimes($in, $out);
        $day = $in->setTimezone($this->context->farm()->timezone)->toDateString();
        if (Attendance::where('worker_id', $worker->id)->whereDate('work_date', $day)->exists()) {
            throw ApiException::conflict('duplicate', "{$worker->full_name} already has attendance on {$day}; correct that record instead.");
        }

        return DB::transaction(function () use ($worker, $data, $in, $out, $day) {
            $attendance = Attendance::create([
                'worker_id' => $worker->id,
                'work_date' => $day,
                'check_in_at' => $in,
                'check_out_at' => $out,
                'source' => AttendanceSource::Manual,
                'note' => $data['note'],
                'recorded_by' => Auth::id(),
            ]);
            $this->audit->record('workforce.attendance.entered', $attendance, null, ['worker' => $worker->worker_code, 'day' => $day, 'note' => $data['note']]);

            return $attendance->refresh();
        });
    }

    /** Correct times with a reason; the change is audited. */
    public function correct(Attendance $attendance, array $data): Attendance
    {
        $in = isset($data['check_in_at']) ? CarbonImmutable::parse($data['check_in_at'])->utc() : $attendance->check_in_at;
        $out = array_key_exists('check_out_at', $data) ? ($data['check_out_at'] ? CarbonImmutable::parse($data['check_out_at'])->utc() : null) : $attendance->check_out_at;
        $this->assertTimes($in, $out);
        $old = ['check_in_at' => $attendance->check_in_at?->toIso8601ZuluString(), 'check_out_at' => $attendance->check_out_at?->toIso8601ZuluString()];
        $attendance->forceFill(['check_in_at' => $in, 'check_out_at' => $out, 'note' => $data['note']])->save();
        $this->audit->record('workforce.attendance.corrected', $attendance, $old, [
            'check_in_at' => $in->toIso8601ZuluString(), 'check_out_at' => $out?->toIso8601ZuluString(), 'note' => $data['note'],
        ]);

        return $attendance;
    }

    /** Is the worker checked in at this moment? */
    public function onDuty(Worker $worker, CarbonImmutable $at): bool
    {
        return Attendance::where('worker_id', $worker->id)
            ->where('check_in_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('check_out_at')->orWhere('check_out_at', '>=', $at))
            ->exists();
    }

    private function time(?string $value): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        if (! $value) {
            return $now;
        }
        $at = CarbonImmutable::parse($value)->utc();
        if ($at->greaterThan($now->addSeconds(config('sfmtp.sync.max_clock_skew_seconds')))) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['occurred_at' => ['The time is in the future. Check the phone\'s clock.']]);
        }

        return $at;
    }

    private function assertTimes(CarbonImmutable $in, ?CarbonImmutable $out): void
    {
        if ($in->isFuture() || ($out && $out->isFuture())) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['check_in_at' => ['Attendance cannot be in the future.']]);
        }
        if ($out && $out->lessThan($in)) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['check_out_at' => ['Check-out cannot be before check-in.']]);
        }
    }
}
