<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Billing account that owns one or more farms (ADR-0001). Never grants data
 * access by itself: access is always per farm membership.
 */
class Organization extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'owner_user_id', 'status'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class);
    }
}
