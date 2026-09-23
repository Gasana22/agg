<?php

namespace App\Modules\Support\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use HasUuids;

    protected $fillable = ['reference', 'organization_id', 'farm_id', 'opened_by', 'subject', 'status', 'priority', 'assigned_to', 'last_activity_at'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(SupportAccessGrant::class, 'ticket_id');
    }
}
