<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property UserType $user_type
 * @property UserStatus $status
 * @property string $email
 * @property ?Carbon $mfa_enabled_at
 * @property ?Carbon $locked_until
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasFactory, HasUuids, Notifiable;

    protected $fillable = ['user_type', 'name', 'email', 'phone', 'password', 'status'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'user_type' => UserType::class,
            'status' => UserStatus::class,
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'failed_logins' => 'integer',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function mfaFactors(): HasMany
    {
        return $this->hasMany(UserMfaFactor::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->user_type === UserType::PlatformAdmin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function hasMfa(): bool
    {
        return $this->mfa_enabled_at !== null;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function confirmedTotp(): ?UserMfaFactor
    {
        return $this->mfaFactors()->where('type', 'totp')->whereNotNull('confirmed_at')->first();
    }
}
