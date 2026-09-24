<?php

namespace App\Modules\Livestock\Domain\Models;

class Movement extends AnimalRecord
{
    protected $table = 'animal_movements';

    protected $fillable = ['farm_id', 'animal_id', 'group_id', 'from_location_id', 'to_location_id', 'moved_at', 'reason', 'notes', 'worker_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['moved_at' => 'datetime'];
    }

    public static function recordType(): string
    {
        return 'movement';
    }
}
