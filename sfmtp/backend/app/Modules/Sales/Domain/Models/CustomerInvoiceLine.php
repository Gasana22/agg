<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Finance\Domain\Models\LedgerAccount;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerInvoiceLine extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = ['farm_id', 'invoice_id', 'position', 'description', 'quantity', 'unit', 'unit_price', 'amount', 'account_id',
        'cost_center_type', 'cost_center_id', 'cost_center_label', 'animal_sale_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
