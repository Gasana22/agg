<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Farm-level policy (docs/04 §3 notes): MFA for everyone, approval
 * thresholds, negative stock, units. Stored as one JSON document per farm and
 * always read merged over the defaults, so new keys need no migration.
 */
class FarmSettings
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function get(Farm $farm): array
    {
        $stored = DB::table('farm_settings')->where('farm_id', $farm->id)->value('settings');

        return array_replace_recursive(FarmService::defaultSettings(), $stored ? json_decode($stored, true) : []);
    }

    /** @param  array<string,mixed>  $changes  validated, partial */
    public function update(Farm $farm, array $changes): array
    {
        $before = $this->get($farm);
        $after = array_replace_recursive($before, $changes);

        if ($after !== $before) {
            DB::table('farm_settings')->updateOrInsert(
                ['farm_id' => $farm->id],
                ['settings' => json_encode($after), 'updated_at' => now()],
            );
            $this->audit->record('farm.settings_changed', $farm, $this->changed($before, $after), $this->changed($after, $before));
        }

        return $after;
    }

    /** Keys of $a that differ from $b, flattened with dots. */
    private function changed(array $a, array $b): array
    {
        $flatA = Arr::dot($a);
        $flatB = Arr::dot($b);

        return array_filter($flatA, fn ($v, $k) => ! array_key_exists($k, $flatB) || $flatB[$k] !== $v, ARRAY_FILTER_USE_BOTH);
    }
}
