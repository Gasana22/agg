<?php

namespace App\Modules\Workforce\Domain\Models;

use App\Modules\Catalog\Domain\Models\ActivityType;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Workforce\Domain\Enums\ActivityStatus;
use App\Modules\Workforce\Domain\Enums\Priority;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of work on a subject, done by one or more workers through tasks.
 *
 * @property string $id
 * @property string $code
 * @property string $module
 * @property string $title
 * @property SubjectType $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property ActivityStatus $status
 */
class Activity extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'activity_type_id', 'module', 'title', 'instructions', 'subject_type', 'subject_id', 'subject_label', 'plot_id', 'location_id',
        'planned_on', 'due_on', 'priority', 'target_quantity', 'target_unit', 'created_by'];

    protected function casts(): array
    {
        return [
            'subject_type' => SubjectType::class,
            'status' => ActivityStatus::class,
            'priority' => Priority::class,
            'planned_on' => 'date',
            'due_on' => 'date',
            'target_quantity' => 'decimal:3',
            'completed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class, 'activity_type_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
