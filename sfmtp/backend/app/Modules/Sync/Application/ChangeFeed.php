<?php

namespace App\Modules\Sync\Application;

use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Appends "this record changed" rows to the farm's change feed. Called from
 * model events, inside the same transaction as the change, so a rolled-back
 * change leaves no feed row.
 */
class ChangeFeed
{
    public function __construct(private readonly TenantContext $context) {}

    public function touch(string $entity, string $recordId, ?string $farmId = null): void
    {
        $farmId ??= $this->context->hasFarm() ? $this->context->farmId() : null;
        if ($farmId === null) {
            return;
        }
        DB::table('sync_changes')->insert([
            'farm_id' => $farmId,
            'entity' => $entity,
            'record_id' => $recordId,
            'changed_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function head(string $farmId): int
    {
        return (int) DB::table('sync_changes')->where('farm_id', $farmId)->max('id');
    }
}
