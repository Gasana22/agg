<?php

namespace App\Modules\Catalog\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of farm work from the global catalogue (weeding, milking, fencing …).
 * `module` says which part of the farm it belongs to.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $module
 * @property bool $is_active
 */
class ActivityType extends Model
{
    use HasUuids;

    protected $table = 'global_activity_types';

    protected $fillable = ['code', 'name', 'module', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
