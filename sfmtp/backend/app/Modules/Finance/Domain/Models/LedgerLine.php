<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $account_id
 * @property string $debit
 * @property string $credit
 * @property string|null $cost_center_type
 * @property string|null $cost_center_id
 */
class LedgerLine extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public $timestamps = false;

    protected $fillable = ['farm_id', 'entry_id', 'account_id', 'debit', 'credit', 'cost_center_type', 'cost_center_id', 'memo'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }
}
