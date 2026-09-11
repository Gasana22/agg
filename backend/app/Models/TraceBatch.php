<?php

namespace App\Models;

use App\Enums\TraceBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TraceBatch extends Model
{
    protected $fillable = [
        'farm_id',
        'code',
        'traceable_type',
        'traceable_id',
        'product_name',
        'quantity',
        'unit',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'status' => TraceBatchStatus::class,
        ];
    }

    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * The production record this batch was registered from — a
     * CropHarvest or an AnimalProductionRecord.
     */
    public function traceable()
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events()
    {
        return $this->hasMany(TraceEvent::class);
    }

    /**
     * Farm managers can always manage a batch; beyond that, authority
     * follows whichever domain it was produced in — an agronomist owns
     * crop batches, a livestock_manager owns animal-product batches.
     */
    public function canBeManagedBy(User $user): bool
    {
        if ($user->canManageFarm($this->farm)) {
            return true;
        }

        return match ($this->traceable_type) {
            CropHarvest::class => $user->canManageCrops($this->farm),
            AnimalProductionRecord::class => $user->canManageLivestock($this->farm),
            default => false,
        };
    }

    /**
     * An 8-character uppercase code, short enough to print legibly under a
     * QR code and to type by hand if a scan fails. Collisions are checked
     * against the table directly since this runs before the model exists.
     */
    public static function generateCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
