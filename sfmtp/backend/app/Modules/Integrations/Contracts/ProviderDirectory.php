<?php

namespace App\Modules\Integrations\Contracts;

use App\Modules\Integrations\Application\ProviderConfig;

/**
 * Where provider settings live. Platform administration keeps them
 * (encrypted, edited in the admin portal) and implements this, so the
 * adapters depend on nothing above them (docs/09).
 */
interface ProviderDirectory
{
    /** @return array<int, ProviderConfig> enabled providers of a kind, the default first */
    public function candidates(string $kind): array;

    public function find(string $id): ?ProviderConfig;

    /** Record the outcome of a call for the admin health view. */
    public function report(string $id, bool $ok, ?string $error = null): void;
}
