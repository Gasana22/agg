<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\Livestock\Domain\Enums\ProductKind;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionRecord extends AnimalRecord
{
    protected $table = 'animal_production_records';

    protected $fillable = ['farm_id', 'animal_id', 'group_id', 'product', 'produced_on', 'session', 'quantity', 'unit', 'discarded', 'trace_batch_id', 'notes', 'worker_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['product' => ProductKind::class, 'produced_on' => 'date', 'quantity' => 'decimal:3', 'discarded' => 'boolean'];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'trace_batch_id');
    }

    public static function recordType(): string
    {
        return 'production';
    }
}
