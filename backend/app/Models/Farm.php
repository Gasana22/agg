<?php

namespace App\Models;

use App\Enums\FarmRole;
use Illuminate\Database\Eloquent\Model;

class Farm extends Model
{
    protected $fillable = [
        'owner_id',
        'name',
        'district',
        'village',
        'gps_lat',
        'gps_lng',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::created(function (Farm $farm) {
            $farm->users()->syncWithoutDetaching([
                $farm->owner_id => ['role_on_farm' => FarmRole::FarmOwner->value],
            ]);
        });
    }

    protected function casts(): array
    {
        return [
            'gps_lat' => 'decimal:7',
            'gps_lng' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Users assigned to this farm, with their role on this specific farm
     * (farm_owner, farm_manager, agronomist, ...). A user's role here is
     * independent of their role on any other farm, and independent of
     * whether they hold a platform-wide role like system_administrator.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)
            ->using(FarmUser::class)
            ->withPivot('role_on_farm')
            ->withTimestamps();
    }

    public function blocks()
    {
        return $this->hasMany(Block::class);
    }

    public function workerProfiles()
    {
        return $this->hasMany(WorkerProfile::class);
    }

    public function dailyTasks()
    {
        return $this->hasMany(DailyTask::class);
    }

    public function crops()
    {
        return $this->hasMany(Crop::class);
    }

    public function cropSeasons()
    {
        return $this->hasMany(CropSeason::class);
    }

    public function animals()
    {
        return $this->hasMany(Animal::class);
    }

    public function breedingRecords()
    {
        return $this->hasMany(BreedingRecord::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function payrollPayments()
    {
        return $this->hasMany(PayrollPayment::class);
    }
}
