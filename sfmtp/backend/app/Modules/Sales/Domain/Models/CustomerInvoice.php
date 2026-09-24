<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An invoice to a customer.
 *
 * @property string $status draft | issued | paid | void
 */
class CustomerInvoice extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = ['farm_id', 'code', 'status', 'customer_id', 'invoice_date', 'due_on', 'amount', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_on' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'issued_at' => 'datetime', 'voided_at' => 'datetime', 'version' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class, 'invoice_id')->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
