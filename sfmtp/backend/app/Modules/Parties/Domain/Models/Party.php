<?php

namespace App\Modules\Parties\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier or customer company (or person) with portal access, global
 * across farms (ADR-0002). Farms keep their own supplier / customer rows;
 * a `PartyLink` connects one of those rows to this party.
 *
 * @property string $id
 * @property string $name
 * @property string $status
 */
class Party extends Model
{
    use HasUuids, Versioned;

    protected $fillable = ['name', 'email', 'phone', 'address', 'tax_id', 'status'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'party_users')->withPivot('id', 'created_at');
    }

    public function links(): HasMany
    {
        return $this->hasMany(PartyLink::class);
    }

    public function hasUser(string $userId): bool
    {
        return $this->users()->whereKey($userId)->exists();
    }

    /** @return array<string, mixed> what the party's own people see */
    public function toProfile(): array
    {
        return [
            'id' => $this->id,
            'type' => 'party',
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'tax_id' => $this->tax_id,
            'status' => $this->status,
            'version' => $this->version,
        ];
    }
}
