<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An account in the farm's chart of accounts.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $type asset | liability | equity | income | expense
 * @property bool $is_system
 */
class LedgerAccount extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = ['farm_id', 'code', 'name', 'type', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    /** Debit-normal accounts grow with debits. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }
}
