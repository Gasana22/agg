<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An append-only record about an animal or a group: health, feeding,
 * weight, production, movement. Mistakes are voided, never edited.
 *
 * @property string $id
 * @property string $farm_id
 * @property string|null $animal_id
 * @property string|null $group_id
 * @property string|null $recorded_by
 */
abstract class AnimalRecord extends Model
{
    use BelongsToFarm, HasUuids;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** Short type name used in URLs, voids and trace payloads. */
    abstract public static function recordType(): string;

    protected static function booted(): void
    {
        static::updating(fn () => throw new AppendOnlyViolation);
        static::deleting(fn () => throw new AppendOnlyViolation);
    }

    public function animal(): BelongsTo
    {
        return $this->belongsTo(Animal::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AnimalGroup::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function void(): HasOne
    {
        return $this->hasOne(AnimalRecordVoid::class, 'record_id')->where('record_type', static::recordType());
    }

    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('id'), fn ($q) => $q->select('record_id')->from('animal_record_voids')->where('record_type', static::recordType()));
    }
}
