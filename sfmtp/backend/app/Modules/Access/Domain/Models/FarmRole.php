<?php

namespace App\Modules\Access\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $id
 * @property string $key
 * @property bool $is_locked
 */
class FarmRole extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = ['farm_id', 'key', 'name', 'description', 'is_system', 'is_locked'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_locked' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'farm_role_permissions')->withPivot('scope');
    }
}
