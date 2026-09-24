<?php

namespace App\Modules\Sync\Domain\Models;

use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use App\Support\Database\Versioned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $status open | resolved
 * @property array<int, array{field:string, base:mixed, mine:mixed, server:mixed}> $fields
 */
class SyncConflict extends Model
{
    use BelongsToFarm, HasUuids, Versioned;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['farm_id', 'user_id', 'mutation_id', 'entity', 'record_id', 'label', 'base_version', 'server_version', 'fields', 'status'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'resolution' => 'array', 'resolved_at' => 'datetime', 'version' => 'integer'];
    }

    public function toArrayForMember(): array
    {
        return [
            'id' => $this->id,
            'type' => 'sync_conflict',
            'entity' => $this->entity,
            'record_id' => $this->record_id,
            'label' => $this->label,
            // A stable key order (JSON columns reorder keys on MySQL).
            'fields' => array_map(fn (array $f) => ['field' => $f['field'], 'base' => $f['base'] ?? null, 'mine' => $f['mine'] ?? null, 'server' => $f['server'] ?? null], $this->fields),
            'status' => $this->status,
            'resolution' => $this->resolution,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'resolved_at' => $this->resolved_at?->toIso8601ZuluString(),
            'version' => $this->version,
        ];
    }
}
