<?php

namespace App\Models;

use App\Enums\PoultryFlockStatus;
use Illuminate\Database\Eloquent\Model;

class PoultryFlock extends Model
{
    protected $fillable = [
        'farm_id',
        'flock_code',
        'name',
        'bird_type',
        'breed',
        'initial_count',
        'source',
        'acquired_date',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'initial_count' => 'integer',
            'acquired_date' => 'date',
            'status' => PoultryFlockStatus::class,
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function mortalityLogs()
    {
        return $this->hasMany(PoultryMortalityLog::class);
    }

    public function productionRecords()
    {
        return $this->hasMany(PoultryProductionRecord::class);
    }

    public function sales()
    {
        return $this->hasMany(PoultrySale::class);
    }

    /**
     * Birds on hand right now: never stored directly, always derived from
     * the initial count minus every recorded death and sale, so there is
     * no running total to drift out of sync.
     */
    public function currentCount(): int
    {
        $dead = (int) $this->mortalityLogs()->sum('quantity');
        $sold = (int) $this->sales()->sum('quantity');

        return $this->initial_count - $dead - $sold;
    }
}
