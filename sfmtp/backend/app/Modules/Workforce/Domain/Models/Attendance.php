<?php

namespace App\Modules\Workforce\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Workforce\Domain\Enums\AttendanceSource;
use App\Support\Database\Versioned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A worker's day: one check-in and, later, one check-out.
 *
 * @property string $id
 * @property string $worker_id
 * @property CarbonImmutable $work_date
 * @property CarbonImmutable $check_in_at
 * @property CarbonImmutable|null $check_out_at
 * @property AttendanceSource $source
 */
class Attendance extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $table = 'worker_attendance';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['id', 'farm_id', 'worker_id', 'work_date', 'check_in_at', 'check_in_lat', 'check_in_lng', 'check_in_accuracy_m', 'check_in_photo_id',
        'check_out_at', 'check_out_lat', 'check_out_lng', 'check_out_accuracy_m', 'check_out_photo_id', 'source', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'work_date' => 'immutable_date',
            'check_in_at' => 'immutable_datetime',
            'check_out_at' => 'immutable_datetime',
            'source' => AttendanceSource::class,
            'check_in_lat' => 'float', 'check_in_lng' => 'float', 'check_in_accuracy_m' => 'float',
            'check_out_lat' => 'float', 'check_out_lng' => 'float', 'check_out_accuracy_m' => 'float',
            'version' => 'integer',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Minutes between check-in and check-out; null while still checked in. */
    public function minutes(): ?int
    {
        return $this->check_out_at ? (int) round($this->check_in_at->diffInSeconds($this->check_out_at) / 60) : null;
    }
}
