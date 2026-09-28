<?php

namespace App\Modules\Platform\Application;

use App\Modules\Integrations\Application\ProviderConfig;
use App\Modules\Integrations\Contracts\ProviderDirectory;
use App\Modules\Platform\Domain\Models\IntegrationProvider;
use Illuminate\Support\Facades\DB;

/** Provider settings from the admin portal's integrations page. */
class PlatformProviderDirectory implements ProviderDirectory
{
    public function candidates(string $kind): array
    {
        return IntegrationProvider::where('kind', $kind)->where('is_enabled', true)
            ->orderByDesc('is_default')->orderBy('priority')->orderBy('name')->get()
            ->map(fn (IntegrationProvider $p) => $this->toConfig($p))->all();
    }

    public function find(string $id): ?ProviderConfig
    {
        $p = IntegrationProvider::find($id);

        return $p ? $this->toConfig($p) : null;
    }

    public function report(string $id, bool $ok, ?string $error = null): void
    {
        // A plain update: health is not an admin change, so it is not audited.
        DB::table('integration_providers')->where('id', $id)->update($ok
            ? ['last_success_at' => now(), 'consecutive_failures' => 0]
            : ['last_failure_at' => now(), 'last_error' => mb_substr((string) $error, 0, 500), 'consecutive_failures' => DB::raw('consecutive_failures + 1')]);
    }

    private function toConfig(IntegrationProvider $p): ProviderConfig
    {
        return new ProviderConfig($p->id, $p->kind, $p->provider, $p->name, array_map(fn ($v) => (string) $v, $p->config ?? []));
    }
}
