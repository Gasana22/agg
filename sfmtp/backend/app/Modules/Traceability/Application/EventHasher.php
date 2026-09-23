<?php

namespace App\Modules\Traceability\Application;

use Carbon\CarbonImmutable;

/**
 * Canonical form and hash of a trace event (docs/07 §3):
 *   hash = SHA-256(prev_hash || canonical_json(event))
 *
 * Every value is normalised the same way whether it comes from the Recorder
 * or is read back from PostgreSQL / MySQL, so the chain verifies on both.
 */
final class EventHasher
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @param  array<string,mixed>  $event  raw column values */
    public static function hash(string $prevHash, array $event): string
    {
        return hash('sha256', $prevHash.self::canonical($event));
    }

    /** @param  array<string,mixed>  $e */
    public static function canonical(array $e): string
    {
        $payload = $e['payload'] ?? [];
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        $fields = [
            'id' => (string) $e['id'],
            'farm_id' => (string) $e['farm_id'],
            'farm_seq' => (int) $e['farm_seq'],
            'batch_id' => (string) $e['batch_id'],
            'event_type' => (string) $e['event_type'],
            'occurred_at' => self::time($e['occurred_at']),
            'actor_user_id' => self::str($e['actor_user_id'] ?? null),
            'worker_id' => self::str($e['worker_id'] ?? null),
            'plot_id' => self::str($e['plot_id'] ?? null),
            'latitude' => self::decimal($e['latitude'] ?? null, 7),
            'longitude' => self::decimal($e['longitude'] ?? null, 7),
            'gps_accuracy_m' => self::decimal($e['gps_accuracy_m'] ?? null, 2),
            'subject_type' => self::str($e['subject_type'] ?? null),
            'subject_id' => self::str($e['subject_id'] ?? null),
            'corrects_event_id' => self::str($e['corrects_event_id'] ?? null),
            'payload' => self::sortKeys($payload),
        ];

        return json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** Normalise a payload the way it will look after a JSON round-trip through the DB. */
    public static function normalisePayload(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function time(mixed $value): string
    {
        $time = $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value, 'UTC');

        return $time->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function str(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function decimal(mixed $value, int $places): ?string
    {
        return $value === null ? null : number_format((float) $value, $places, '.', '');
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
