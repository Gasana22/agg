<?php

namespace App\Modules\Access\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $farm_id
 * @property string $email
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
class FarmInvitation extends Model
{
    use BelongsToFarm, HasUuids;

    public const STATUSES = ['pending', 'accepted', 'revoked', 'expired'];

    protected $fillable = ['farm_id', 'email', 'token_hash', 'message', 'invited_by', 'expires_at', 'last_sent_at', 'send_count'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'pending' => $query->pending(),
            'accepted' => $query->whereNotNull('accepted_at'),
            'revoked' => $query->whereNull('accepted_at')->whereNotNull('revoked_at'),
            'expired' => $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now()),
        };
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(FarmRole::class, 'farm_invitation_roles', 'invitation_id', 'farm_role_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
