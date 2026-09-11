<?php

namespace App\Models;

use App\Enums\TraceEventType;
use Illuminate\Database\Eloquent\Model;

class TraceEvent extends Model
{
    protected $fillable = [
        'trace_batch_id',
        'type',
        'date',
        'location',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => TraceEventType::class,
        ];
    }

    public function traceBatch()
    {
        return $this->belongsTo(TraceBatch::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
