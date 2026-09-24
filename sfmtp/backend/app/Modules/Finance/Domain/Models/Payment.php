<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received for a customer invoice, or paid out for a supplier invoice,
 * an expense or a payroll run. Posted when recorded; voided, never edited.
 */
class Payment extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = ['farm_id', 'code', 'direction', 'status', 'payable_type', 'payable_id', 'payable_code', 'party', 'amount', 'paid_on',
        'method', 'account_id', 'reference', 'note', 'ledger_entry_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
