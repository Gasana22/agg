<?php

namespace App\Modules\Platform\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Platform\Domain\Models\IntegrationProvider;
use Illuminate\Support\Facades\DB;

/**
 * External provider configuration (docs/01 §2, requirements §36). Secrets are
 * encrypted at rest and never returned: responses show a masked value.
 * Adapters that actually call providers arrive in Phase 14.
 */
class Integrations
{
    public const PROVIDERS = [
        'sms' => ['africas_talking', 'twilio'],
        'email' => ['smtp', 'sendgrid'],
        'maps' => ['google', 'mapbox'],
        'weather' => ['openweather', 'tomorrow_io'],
        'payment' => ['flutterwave', 'manual'],
        'push' => ['fcm'],
        'accounting' => ['quickbooks', 'xero'],
    ];

    private const SECRET_PATTERN = '/(key|secret|token|password|credential)/i';

    public const MASK_PREFIX = '••••';

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data): IntegrationProvider
    {
        return DB::transaction(function () use ($data) {
            $provider = IntegrationProvider::create($data + ['config' => []]);
            $this->enforceSingleDefault($provider);
            $this->audit->record('admin.integration_created', $provider, null, $this->summary($provider));

            return $provider;
        });
    }

    /**
     * Config keys set to null are removed; masked values sent back unchanged
     * keep the stored secret.
     */
    public function update(IntegrationProvider $provider, array $data): IntegrationProvider
    {
        return DB::transaction(function () use ($provider, $data) {
            $before = $this->summary($provider);

            if (array_key_exists('config', $data)) {
                $config = $provider->config ?? [];
                foreach ($data['config'] as $key => $value) {
                    if ($value === null) {
                        unset($config[$key]);
                    } elseif (! (is_string($value) && str_starts_with($value, self::MASK_PREFIX))) {
                        $config[$key] = $value;
                    }
                }
                $data['config'] = $config;
            }

            $provider->fill($data)->save();
            $this->enforceSingleDefault($provider);
            $this->audit->record('admin.integration_updated', $provider, $before, $this->summary($provider));

            return $provider;
        });
    }

    public function delete(IntegrationProvider $provider): void
    {
        $this->audit->record('admin.integration_deleted', $provider, $this->summary($provider), null);
        $provider->delete();
    }

    /** @return array<string,mixed> config with secrets masked */
    public static function maskedConfig(IntegrationProvider $provider): array
    {
        $out = [];
        foreach ($provider->config ?? [] as $key => $value) {
            $out[$key] = preg_match(self::SECRET_PATTERN, $key) && is_string($value) && $value !== ''
                ? self::MASK_PREFIX.substr($value, -4)
                : $value;
        }

        return $out;
    }

    private function enforceSingleDefault(IntegrationProvider $provider): void
    {
        if ($provider->is_default) {
            IntegrationProvider::where('kind', $provider->kind)->whereKeyNot($provider->id)->update(['is_default' => false]);
        }
    }

    /** Audit summary: which keys are set, never their values. */
    private function summary(IntegrationProvider $provider): array
    {
        return [
            'kind' => $provider->kind,
            'provider' => $provider->provider,
            'is_enabled' => $provider->is_enabled,
            'is_default' => $provider->is_default,
            'config_keys' => array_keys($provider->config ?? []),
        ];
    }
}
