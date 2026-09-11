<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\FarmRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Farms this user is assigned to, with their role on each farm.
     * Not a system_administrator concern: that role manages the platform,
     * not farm membership.
     */
    public function farms()
    {
        return $this->belongsToMany(Farm::class)
            ->using(FarmUser::class)
            ->withPivot('role_on_farm')
            ->withTimestamps();
    }

    /**
     * This user's role on the given farm, or null if they aren't assigned
     * to it at all. Independent of any platform-wide role.
     */
    public function roleOnFarm(Farm $farm): ?FarmRole
    {
        $membership = $this->farms()->where('farm_id', $farm->id)->first();

        return $membership?->pivot->role_on_farm;
    }

    /**
     * Platform-wide administrators manage the system itself — farm
     * accounts, platform users, and settings — not any farm's day-to-day
     * operations. Deliberately does NOT bypass canViewFarm()/canManageFarm()
     * below (or anything built on them): a system_administrator with no
     * role_on_farm has no more business reading a farm's crops, livestock,
     * finances, or staff than a stranger would. Where the admin genuinely
     * does manage something — the Farm record itself (FarmPolicy), the
     * platform-wide dashboard, the full farm list — that's authorized
     * explicitly against this flag at the call site instead.
     */
    public function isSystemAdministrator(): bool
    {
        return $this->hasRole('system_administrator');
    }

    /**
     * Farm-operations authority: any member of the farm, in any role. Not
     * bypassed by system_administrator — see isSystemAdministrator().
     */
    public function canViewFarm(Farm $farm): bool
    {
        return $this->roleOnFarm($farm) !== null;
    }

    /**
     * Farm-operations authority: the farm's owner or manager. Not bypassed
     * by system_administrator — see isSystemAdministrator().
     */
    public function canManageFarm(Farm $farm): bool
    {
        return in_array($this->roleOnFarm($farm), [FarmRole::FarmOwner, FarmRole::FarmManager], true);
    }

    /**
     * Crop-specific authority: everyone canManageFarm() covers, plus the
     * agronomist role itself — otherwise that role would have no more
     * authority over crop records than a random farm member, which
     * defeats the point of naming it. Crop sales are deliberately excluded
     * (see CropSalePolicy) — revenue is Accountant/manager territory, not
     * an agronomist's.
     */
    public function canManageCrops(Farm $farm): bool
    {
        return $this->canManageFarm($farm)
            || $this->roleOnFarm($farm) === FarmRole::Agronomist;
    }

    /**
     * Livestock-specific authority: everyone canManageFarm() covers, plus
     * the livestock_manager role itself — otherwise that role would have
     * no more authority over animals than a random farm member, which
     * defeats the point of naming it.
     */
    public function canManageLivestock(Farm $farm): bool
    {
        return $this->canManageFarm($farm)
            || $this->roleOnFarm($farm) === FarmRole::LivestockManager;
    }

    /**
     * Procurement authority: everyone canManageFarm() covers, plus
     * store_manager — ordering and receiving supplies is that role's job.
     */
    public function canManageProcurement(Farm $farm): bool
    {
        return $this->canManageFarm($farm)
            || $this->roleOnFarm($farm) === FarmRole::StoreManager;
    }

    /**
     * Financial-record authority: everyone canManageFarm() covers, plus
     * accountant — bookkeeping (expenses, payroll disbursement, supplier
     * payments, reading the P&L) is that role's job, without granting
     * authority over farm operations themselves.
     */
    public function canManageFinance(Farm $farm): bool
    {
        return $this->canManageFarm($farm)
            || $this->roleOnFarm($farm) === FarmRole::Accountant;
    }

    /**
     * Inventory authority: everyone canManageFarm() covers, plus
     * store_manager — the same role that receives goods from suppliers is
     * the one who owns the stock ledger they end up in.
     */
    public function canManageInventory(Farm $farm): bool
    {
        return $this->canManageFarm($farm)
            || $this->roleOnFarm($farm) === FarmRole::StoreManager;
    }

    /**
     * Asset authority: everyone canManageFarm() covers, plus store_manager
     * — equipment and tools live in the same "store" remit as procurement
     * and inventory.
     */
    public function canManageAssets(Farm $farm): bool
    {
        return $this->canManageFarm($farm)
            || $this->roleOnFarm($farm) === FarmRole::StoreManager;
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class)->latest();
    }

    public function isSupervisorOf(WorkerProfile $profile): bool
    {
        return $profile->supervisor_id === $this->id;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'platform_roles' => $this->getRoleNames(),
        ];
    }
}
