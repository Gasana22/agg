<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's membership of one farm. Roles are attached by the Access module.
 *
 * @property string $id
 * @property string $farm_id
 * @property string $user_id
 * @property bool $is_owner
 * @property MembershipStatus $status
 */
class FarmUser extends Model
{
    use HasUuids;

    protected $fillable = ['farm_id', 'user_id', 'status', 'is_owner', 'invited_by', 'joined_at'];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'is_owner' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
