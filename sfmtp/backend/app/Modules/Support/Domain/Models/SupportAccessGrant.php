<?php

namespace App\Modules\Support\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SupportAccessGrant extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['farm_id', 'ticket_id', 'granted_by', 'grantee_user_id', 'expires_at', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
