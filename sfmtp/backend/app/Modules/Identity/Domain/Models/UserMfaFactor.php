<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserMfaFactor extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'type', 'secret', 'last_used_timestep', 'confirmed_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'confirmed_at' => 'datetime',
            'last_used_timestep' => 'integer',
        ];
    }
}
