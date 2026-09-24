<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received without an invoice: a cash sale at the gate, a grant,
 * manure sold to a neighbour. Posted when recorded; voided, never edited.
 */
class IncomeRecord extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'status', 'account_id', 'received_into_account_id', 'amount', 'received_on', 'payer', 'description',
        'cost_center_type', 'cost_center_id', 'cost_center_label', 'media_id', 'ledger_entry_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_on' => 'date', 'voided_at' => 'datetime', 'version' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    public function receivedInto(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'received_into_account_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
