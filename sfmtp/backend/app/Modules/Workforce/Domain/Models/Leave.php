<?php

namespace App\Modules\Workforce\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Workforce\Domain\Enums\LeaveKind;
use App\Modules\Workforce\Domain\Enums\LeaveStatus;
use App\Support\Database\Versioned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A leave request.
 *
 * @property string $id
 * @property string $worker_id
 * @property LeaveKind $kind
 * @property LeaveStatus $status
 * @property CarbonImmutable $from_on
 * @property CarbonImmutable $to_on
 * @property string|null $requested_by
 */
class Leave extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $table = 'worker_leave';

    protected $fillable = ['id', 'farm_id', 'worker_id', 'kind', 'from_on', 'to_on', 'reason', 'requested_by'];

    protected function casts(): array
    {
        return [
            'kind' => LeaveKind::class,
            'status' => LeaveStatus::class,
            'from_on' => 'immutable_date',
            'to_on' => 'immutable_date',
            'decided_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** Calendar days, both ends included. */
    public function days(): int
    {
        return (int) $this->from_on->diffInDays($this->to_on) + 1;
    }
}
