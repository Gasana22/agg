<?php

namespace App\Modules\Livestock\Domain\Models;

use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Enums\Origin;
use App\Modules\Livestock\Domain\Enums\Sex;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Modules\Traceability\Domain\Models\TraceBatch;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One animal. Its `animal` trace batch carries its history and gives it a
 * public-safe identifier (docs/03 §5).
 *
 * @property string $id
 * @property string $farm_id
 * @property string $animal_code
 * @property string|null $tag_number
 * @property string|null $name
 * @property string $species_id
 * @property Sex $sex
 * @property AnimalStatus $status
 * @property string|null $group_id
 * @property string|null $location_id
 * @property string|null $trace_batch_id
 * @property Carbon|null $meat_withdrawal_until
 * @property Carbon|null $milk_withdrawal_until
 */
class Animal extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $fillable = [
        'farm_id', 'animal_code', 'tag_number', 'rfid', 'name', 'species_id', 'breed_id', 'breed_note', 'sex',
        'birth_date', 'birth_date_estimated', 'origin', 'acquired_on', 'dam_id', 'sire_id', 'parentage_note',
        'group_id', 'location_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sex' => Sex::class,
            'origin' => Origin::class,
            'status' => AnimalStatus::class,
            'birth_date' => 'date',
            'birth_date_estimated' => 'boolean',
            'acquired_on' => 'date',
            'exited_on' => 'date',
            'last_weighed_on' => 'date',
            'last_weight_kg' => 'decimal:2',
            'meat_withdrawal_until' => 'date',
            'milk_withdrawal_until' => 'date',
            'version' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === AnimalStatus::Active;
    }

    public function label(): string
    {
        return trim($this->animal_code.($this->name ? " “{$this->name}”" : '').($this->tag_number ? " · tag {$this->tag_number}" : ''));
    }

    public function speciesCode(): ?string
    {
        return DB::table('global_animal_species')->where('id', $this->species_id)->value('code');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AnimalGroup::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function dam(): BelongsTo
    {
        return $this->belongsTo(self::class, 'dam_id');
    }

    public function sire(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sire_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TraceBatch::class, 'trace_batch_id');
    }
}
