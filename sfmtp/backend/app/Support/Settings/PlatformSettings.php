<?php

namespace App\Support\Settings;

use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Validated key/value platform settings (System Administrator data). Only the
 * keys in schema() exist. Shared infrastructure so any module can read them.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform:settings';

    /** @return array<string, array{label:string, rules:array, default:mixed}> */
    public static function schema(): array
    {
        return [
            'billing.trial_days' => ['label' => 'Free trial length (days)', 'rules' => ['integer', 'min:0', 'max:90'], 'default' => 30],
            'billing.grace_days' => ['label' => 'Grace period after a missed renewal (days)', 'rules' => ['integer', 'min:0', 'max:60'], 'default' => 7],
            'billing.default_plan_code' => ['label' => 'Plan new organizations start on', 'rules' => ['string', 'exists:subscription_plans,code'], 'default' => 'growth'],
            'platform.support_email' => ['label' => 'Support email address', 'rules' => ['email', 'max:255'], 'default' => 'support@sfmtp.test'],
        ];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? throw new \InvalidArgumentException("Unknown platform setting {$key}");
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $stored = DB::table('platform_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all();
            $values = [];
            foreach (self::schema() as $key => $def) {
                $values[$key] = array_key_exists($key, $stored) ? $stored[$key] : $def['default'];
            }

            return $values;
        });
    }

    /** @param  array<string,mixed>  $values */
    public function update(array $values, string $actorId): array
    {
        $schema = self::schema();
        $unknown = array_diff(array_keys($values), array_keys($schema));
        if ($unknown) {
            throw ApiException::unprocessable('validation_failed', 'Unknown settings.', array_fill_keys(array_map(fn ($k) => "settings.{$k}", $unknown), ['Unknown setting.']));
        }

        $rules = [];
        foreach ($values as $key => $value) {
            $rules[str_replace('.', '__', $key)] = array_merge(['required'], $schema[$key]['rules']);
        }
        $flat = [];
        foreach ($values as $key => $value) {
            $flat[str_replace('.', '__', $key)] = $value;
        }
        $validator = Validator::make($flat, $rules);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $field => $messages) {
                $errors['settings.'.str_replace('__', '.', $field)] = $messages;
            }
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', $errors);
        }

        foreach ($values as $key => $value) {
            $value = in_array('integer', $schema[$key]['rules'], true) ? (int) $value : $value;
            DB::table('platform_settings')->upsert(
                [['key' => $key, 'value' => json_encode($value), 'updated_by' => $actorId, 'updated_at' => now()]],
                ['key'],
                ['value', 'updated_by', 'updated_at'],
            );
        }
        Cache::forget(self::CACHE_KEY);

        return $this->all();
    }
}
