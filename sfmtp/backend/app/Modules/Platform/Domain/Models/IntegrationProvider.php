<?php

namespace App\Modules\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegrationProvider extends Model
{
    use HasUuids;

    protected $fillable = ['kind', 'provider', 'name', 'config', 'is_enabled', 'is_default'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
