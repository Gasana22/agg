<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\Livestock\Domain\Enums\WeightMethod;

class Weight extends AnimalRecord
{
    protected $table = 'animal_weights';

    protected $fillable = ['farm_id', 'animal_id', 'group_id', 'weighed_on', 'weight_kg', 'method', 'notes', 'worker_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['weighed_on' => 'date', 'weight_kg' => 'decimal:2', 'method' => WeightMethod::class];
    }

    public static function recordType(): string
    {
        return 'weight';
    }
}
