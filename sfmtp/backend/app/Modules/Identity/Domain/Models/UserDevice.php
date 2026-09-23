<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'client', 'name', 'platform', 'push_token', 'last_ip', 'last_seen_at', 'last_sync_at', 'revoked_at'];

    protected $hidden = ['push_token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
