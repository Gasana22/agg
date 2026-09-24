<?php

namespace App\Modules\Catalog\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A global inventory category (seeds, fertilizers, feed …) with its kind.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $kind
 */
class InventoryCategory extends Model
{
    use HasUuids;

    protected $table = 'global_inventory_categories';

    protected $fillable = ['code', 'name', 'kind', 'is_active'];
}
