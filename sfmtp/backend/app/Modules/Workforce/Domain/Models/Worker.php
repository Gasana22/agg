<?php

namespace App\Modules\Workforce\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Workforce\Domain\Enums\EmploymentType;
use App\Modules\Workforce\Domain\Enums\WorkerStatus;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone who works on the farm. Workers who use the app are linked to
 * their membership; casual workers may have no login at all.
 *
 * @property string $id
 * @property string $worker_code
 * @property string|null $farm_user_id
 * @property string $full_name
 * @property WorkerStatus $status
 * @property EmploymentType $employment_type
 */
class Worker extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'worker_code', 'farm_user_id', 'full_name', 'phone', 'national_id', 'job_title', 'employment_type', 'daily_rate', 'started_on', 'left_on', 'status', 'notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'status' => WorkerStatus::class,
            'daily_rate' => 'decimal:2',
            'started_on' => 'date',
            'left_on' => 'date',
            'version' => 'integer',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(FarmUser::class, 'farm_user_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isActive(): bool
    {
        return $this->status === WorkerStatus::Active;
    }
}
