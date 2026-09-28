<?php

namespace App\Modules\Integrations\Application;

/** One configured provider: its kind, adapter key and (decrypted) settings. */
final class ProviderConfig
{
    /** @param  array<string, string>  $config */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $provider,
        public readonly string $name,
        public readonly array $config,
    ) {}

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->config[$key] ?? null;

        return $value === null || $value === '' ? $default : (string) $value;
    }

    /** A required setting; a missing one is a configuration problem, not an outage. */
    public function require(string $key): string
    {
        return $this->get($key) ?? throw new ProviderFailure("{$this->name}: the setting \"{$key}\" is missing.", retryable: true);
    }
}
