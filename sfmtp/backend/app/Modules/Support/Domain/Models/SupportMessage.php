<?php

namespace App\Modules\Support\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportMessage extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Microsecond precision so entries written in the same second keep their order. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $table = 'support_ticket_messages';

    protected $fillable = ['ticket_id', 'author_id', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
