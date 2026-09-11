<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Records the response of a request made with an Idempotency-Key header,
 * so a retried submission (the exact scenario an offline client hits when
 * it queues an action, sends it, loses the response to a dropped
 * connection, and retries) replays the original response instead of
 * creating a duplicate record.
 */
class IdempotencyKey extends Model
{
    protected $fillable = [
        'key',
        'user_id',
        'route',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
