<?php

namespace App\Modules\Workforce\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Workforce\Domain\Enums\TaskEvent;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a task's life. `applied = false` marks an offline action the
 * server refused (for example, completing a task that was cancelled in the
 * meantime); it is kept as evidence of the work.
 *
 * @property string $id
 * @property string $task_id
 * @property TaskEvent $event
 * @property bool $applied
 */
class TaskLog extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public const UPDATED_AT = null;

    protected $table = 'worker_task_logs';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['id', 'farm_id', 'task_id', 'event', 'from_status', 'to_status', 'applied', 'occurred_at', 'lat', 'lng', 'accuracy_m', 'quantity', 'unit', 'note', 'device_id', 'recorded_by'];

    protected function casts(): array
    {
        return [
            'event' => TaskEvent::class,
            'applied' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'lat' => 'float',
            'lng' => 'float',
            'accuracy_m' => 'float',
            'quantity' => 'decimal:3',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
