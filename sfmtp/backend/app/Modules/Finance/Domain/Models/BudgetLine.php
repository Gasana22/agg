<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = ['farm_id', 'budget_id', 'account_id', 'amount', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
