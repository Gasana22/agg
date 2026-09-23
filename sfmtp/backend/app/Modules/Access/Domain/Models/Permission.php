<?php

namespace App\Modules\Access\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasUuids;

    protected $fillable = ['key', 'module', 'description', 'scopes', 'owner_only', 'money'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'owner_only' => 'boolean', 'money' => 'boolean'];
    }
}
