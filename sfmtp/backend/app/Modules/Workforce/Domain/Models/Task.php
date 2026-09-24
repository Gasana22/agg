<?php

namespace App\Modules\Workforce\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One worker's part of an activity. Its status only changes through
 * TaskFlow, which writes a log entry for every step.
 *
 * @property string $id
 * @property string $code
 * @property string $activity_id
 * @property string $worker_id
 * @property TaskStatus $status
 * @property int $version
 * @property Activity $activity
 * @property Worker $worker
 */
class Task extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $table = 'worker_tasks';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'code', 'activity_id', 'worker_id', 'assigned_by', 'due_on'];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'due_on' => 'date',
            'started_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'quantity' => 'decimal:3',
            'worked_minutes' => 'integer',
            'version' => 'integer',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class)->orderBy('occurred_at')->orderBy('created_at');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TaskPhoto::class)->orderBy('created_at');
    }
}
