<?php

namespace App\Modules\Livestock\Domain\Models;

class Feeding extends AnimalRecord
{
    protected $table = 'animal_feedings';

    protected $fillable = ['farm_id', 'animal_id', 'group_id', 'fed_on', 'feed_name', 'quantity', 'unit', 'input_batch_id', 'notes', 'worker_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['fed_on' => 'date', 'quantity' => 'decimal:3'];
    }

    public static function recordType(): string
    {
        return 'feeding';
    }
}
