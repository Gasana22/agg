<?php

namespace App\Modules\Parties\Domain\Models;

use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One farm's supplier or customer record, opened to a party's portal.
 *
 * @property string $id
 * @property string $party_id
 * @property string $farm_id
 * @property string $kind supplier | customer
 * @property string $record_id
 * @property string $status active | revoked
 * @property Carbon $linked_at
 * @property-read Farm $farm
 * @property-read Party $party
 */
class PartyLink extends Model
{
    use HasUuids;

    public const KINDS = ['supplier', 'customer'];

    protected $fillable = ['party_id', 'farm_id', 'kind', 'record_id', 'status', 'linked_by', 'linked_at', 'revoked_by', 'revoked_at'];

    protected function casts(): array
    {
        return ['linked_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
