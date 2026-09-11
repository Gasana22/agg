<?php

namespace App\Models;

use App\Enums\FarmRole;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;

/**
 * A simple in-app notification, deliberately independent of Laravel's
 * built-in notification channels/queue (no Notifiable trait, no mail or
 * SMS delivery here) — this is a per-user inbox row created directly at
 * the point in a controller where something notification-worthy happens.
 * Table is named user_notifications (not notifications) to avoid any
 * collision with Laravel's own notifications table convention.
 */
class Notification extends Model
{
    protected $table = 'user_notifications';

    protected $fillable = [
        'user_id',
        'farm_id',
        'type',
        'title',
        'body',
        'related_type',
        'related_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function related()
    {
        return $this->morphTo();
    }

    public function markAsRead(): void
    {
        if ($this->read_at === null) {
            $this->update(['read_at' => now()]);
        }
    }

    public static function send(
        User $user,
        ?Farm $farm,
        NotificationType $type,
        string $title,
        ?string $body = null,
        ?Model $related = null,
    ): self {
        return static::create([
            'user_id' => $user->id,
            'farm_id' => $farm?->id,
            'type' => $type->value,
            'title' => $title,
            'body' => $body,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
        ]);
    }

    /**
     * Notifies every farm member currently holding one of the given
     * FarmRole cases (e.g. FarmOwner + FarmManager for a broad
     * "management needs to know" alert).
     *
     * @param  array<FarmRole>  $roles
     */
    public static function sendToFarmRoles(
        Farm $farm,
        array $roles,
        NotificationType $type,
        string $title,
        ?string $body = null,
        ?Model $related = null,
    ): void {
        $userIds = $farm->users()
            ->wherePivotIn('role_on_farm', array_map(fn ($role) => $role->value, $roles))
            ->pluck('users.id');

        foreach ($userIds as $userId) {
            static::create([
                'user_id' => $userId,
                'farm_id' => $farm->id,
                'type' => $type->value,
                'title' => $title,
                'body' => $body,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
            ]);
        }
    }
}
