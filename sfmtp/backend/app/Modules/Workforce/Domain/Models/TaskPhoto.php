<?php

namespace App\Modules\Workforce\Domain\Models;

use App\Modules\Media\Domain\Models\Media;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo taken as evidence of work on a task.
 *
 * @property string $id
 * @property string $task_id
 * @property string $media_id
 */
class TaskPhoto extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public const UPDATED_AT = null;

    protected $table = 'worker_task_photos';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['id', 'farm_id', 'task_id', 'media_id', 'taken_at', 'lat', 'lng', 'accuracy_m', 'caption', 'recorded_by'];

    protected function casts(): array
    {
        return ['taken_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'lat' => 'float', 'lng' => 'float', 'accuracy_m' => 'float'];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
