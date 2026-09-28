<?php

namespace App\Modules\Platform\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegrationProvider extends Model
{
    use HasUuids;

    protected $fillable = ['kind', 'provider', 'name', 'config', 'is_enabled', 'is_default', 'priority'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }
}
