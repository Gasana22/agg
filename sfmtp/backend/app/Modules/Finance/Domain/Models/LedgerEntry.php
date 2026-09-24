<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Immutable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A balanced journal entry. Never changed; corrected by a reversing entry.
 *
 * @property string $id
 * @property string $number
 * @property CarbonImmutable $posted_on
 * @property string $source_type
 * @property string|null $source_id
 * @property string $memo
 * @property string|null $reverses_entry_id
 */
class LedgerEntry extends Model
{
    use BelongsToFarm, HasUuids, Immutable;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'number', 'posted_on', 'source_type', 'source_id', 'memo', 'reverses_entry_id', 'posted_by'];

    protected function casts(): array
    {
        return ['posted_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class, 'entry_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
