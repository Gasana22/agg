<?php

namespace App\Modules\Integrations\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $reference
 * @property string $provider_id
 * @property string $provider
 * @property string $purpose
 * @property string $subject_id
 * @property string|null $subject_code
 * @property string|null $farm_id
 * @property string $amount
 * @property string $currency
 * @property string $description
 * @property string $status
 * @property string|null $checkout_url
 * @property string|null $provider_tx_id
 * @property string|null $paid_amount
 * @property string|null $failure_reason
 * @property string $return_path
 * @property string $created_by
 * @property Carbon|null $verified_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon $created_at
 */
class OnlinePayment extends Model
{
    use HasUuids;

    protected $fillable = ['reference', 'provider_id', 'provider', 'purpose', 'subject_id', 'subject_code', 'farm_id', 'amount', 'currency', 'description',
        'status', 'return_path', 'created_by'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'fulfilled_at' => 'datetime'];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'purpose' => $this->purpose,
            'subject_id' => $this->subject_id,
            'subject_code' => $this->subject_code,
            'farm_id' => $this->farm_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'status' => $this->status,
            'checkout_url' => $this->status === 'pending' ? $this->checkout_url : null,
            'paid_amount' => $this->paid_amount === null ? null : (float) $this->paid_amount,
            'failure_reason' => $this->failure_reason,
            'return_path' => $this->return_path,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'fulfilled_at' => $this->fulfilled_at?->toIso8601ZuluString(),
        ];
    }
}
