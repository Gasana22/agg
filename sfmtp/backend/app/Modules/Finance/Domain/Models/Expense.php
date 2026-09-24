<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money spent (or to be spent) outside purchasing: fuel, a vet visit, casual
 * labour paid in cash. Requested, approved (posting it), then paid.
 *
 * @property string $status requested | approved | paid | rejected | cancelled | void
 */
class Expense extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'status', 'account_id', 'amount', 'paid_amount', 'spent_on', 'payee', 'description',
        'cost_center_type', 'cost_center_id', 'cost_center_label', 'paid_from_account_id', 'media_id', 'requested_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'spent_on' => 'date', 'decided_at' => 'datetime', 'version' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }

    public function paidFrom(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'paid_from_account_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
