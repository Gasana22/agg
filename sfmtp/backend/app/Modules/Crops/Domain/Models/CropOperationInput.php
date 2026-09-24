<?php

namespace App\Modules\Crops\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $product_name
 * @property string $quantity
 * @property string $unit
 * @property int|null $withholding_days
 * @property string|null $input_batch_id
 */
class CropOperationInput extends Model
{
    use BelongsToFarm, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['farm_id', 'operation_id', 'input_batch_id', 'product_name', 'quantity', 'unit', 'withholding_days'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'withholding_days' => 'integer'];
    }

    public function inputBatch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'input_batch_id');
    }
}
