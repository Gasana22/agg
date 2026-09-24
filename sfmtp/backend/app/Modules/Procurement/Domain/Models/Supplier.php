<?php

namespace App\Modules\Procurement\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A supplier of the farm. `party_id` links a portal account in Phase 12.
 */
class Supplier extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'name', 'contact_person', 'phone', 'email', 'address', 'tax_id', 'payment_terms_days', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'payment_terms_days' => 'integer', 'version' => 'integer'];
    }
}
