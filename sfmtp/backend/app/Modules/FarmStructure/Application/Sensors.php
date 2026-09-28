<?php

namespace App\Modules\FarmStructure\Application;

use App\Modules\FarmStructure\Domain\Models\IotDevice;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * IoT extension point (ADR-0018). A farm registers a sensor and gets a
 * token, shown once; the device posts readings with it. Readings are
 * append-only and carry no people. Metrics are free-form names
 * (`soil_moisture_pct`, `temperature_c`, `rain_mm`, …) so any device fits.
 */
class Sensors
{
    public const MAX_BATCH = 100;

    public function __construct(private readonly TenantContext $context) {}

    /** @return array{0: IotDevice, 1: string} the device and its token (shown once) */
    public function register(array $data): array
    {
        $data = Validator::make($data, $this->rules())->validate();
        $token = 'sfmtpd_'.Str::random(40);
        $count = IotDevice::count() + 1;
        $device = new IotDevice($data + ['code' => sprintf('DEV-%03d', $count), 'created_by' => Auth::id()]);
        while (IotDevice::where('code', $device->code)->exists()) {
            $device->code = sprintf('DEV-%03d', ++$count);
        }
        $device->forceFill(['token_hash' => IotDevice::hashToken($token), 'token_hint' => substr($token, -4)])->save();

        return [$device, $token];
    }

    public function update(IotDevice $device, array $data): IotDevice
    {
        $data = Validator::make($data, array_map(fn ($r) => array_merge(['sometimes'], array_diff($r, ['required'])), $this->rules()) + ['is_active' => ['sometimes', 'boolean']])->validate();
        $device->fill($data)->forceFill(['version' => $device->version + 1])->save();

        return $device;
    }

    /** @return string the new token (the old one stops working) */
    public function rotate(IotDevice $device): string
    {
        $token = 'sfmtpd_'.Str::random(40);
        $device->forceFill(['token_hash' => IotDevice::hashToken($token), 'token_hint' => substr($token, -4)])->save();

        return $token;
    }

    /**
     * Readings from a device, identified by its token alone.
     *
     * @return int readings stored
     */
    public function ingest(?string $token, array $payload): int
    {
        $device = $token ? $this->context->bypass(fn () => IotDevice::withoutGlobalScopes()->where('token_hash', IotDevice::hashToken($token))->first()) : null;
        if (! $device || ! $device->is_active) {
            throw ApiException::unauthenticated('invalid_device_token', 'Unknown or inactive device token.');
        }
        $farm = Farm::findOrFail($device->farm_id);
        if (! in_array($farm->status->value, ['active', 'pending'], true)) {
            throw ApiException::forbidden('farm_not_active', 'This farm does not accept readings.');
        }
        $data = Validator::make($payload, [
            'readings' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'readings.*.metric' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'readings.*.value' => ['required', 'numeric', 'between:-9999999999,9999999999'],
            'readings.*.recorded_at' => ['sometimes', 'date'],
        ])->validate();

        return $this->context->run($farm, function () use ($device, $data) {
            $now = CarbonImmutable::now();
            $rows = [];
            foreach ($data['readings'] as $i => $r) {
                $at = isset($r['recorded_at']) ? CarbonImmutable::parse($r['recorded_at'])->utc() : $now;
                if ($at->greaterThan($now->addMinutes(5)) || $at->lessThan($now->subDays(7))) {
                    throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ["readings.{$i}.recorded_at" => ['Readings must be from the last 7 days.']]);
                }
                $rows[] = ['id' => (string) Str::uuid7(), 'farm_id' => $device->farm_id, 'device_id' => $device->id, 'metric' => $r['metric'],
                    'value' => $r['value'], 'recorded_at' => $at->format('Y-m-d H:i:s.u'), 'received_at' => $now->format('Y-m-d H:i:s.u')];
            }
            DB::table('sensor_readings')->insert($rows);
            DB::table('iot_devices')->where('id', $device->id)->update(['last_seen_at' => $now]);

            return count($rows);
        });
    }

    /** @return array{latest: array<int, array>, series: array<int, array>} */
    public function readings(IotDevice $device, ?string $metric, string $from, string $to): array
    {
        $latest = DB::table('sensor_readings as r')->where('r.farm_id', $device->farm_id)->where('r.device_id', $device->id)
            ->whereIn('r.recorded_at', fn ($q) => $q->from('sensor_readings as m')->where('m.farm_id', $device->farm_id)->where('m.device_id', $device->id)
                ->whereColumn('m.metric', 'r.metric')->selectRaw('MAX(m.recorded_at)'))
            ->orderBy('r.metric')->get(['r.metric', 'r.value', 'r.recorded_at'])->unique('metric')->values()
            ->map(fn ($r) => ['metric' => $r->metric, 'value' => (float) $r->value, 'recorded_at' => CarbonImmutable::parse($r->recorded_at, 'UTC')->toIso8601ZuluString()])->all();
        $series = DB::table('sensor_readings')->where('farm_id', $device->farm_id)->where('device_id', $device->id)
            ->when($metric, fn ($q) => $q->where('metric', $metric))
            ->whereBetween('recorded_at', [$from, $to])->orderBy('recorded_at')->limit(2000)->get(['metric', 'value', 'recorded_at'])
            ->map(fn ($r) => ['metric' => $r->metric, 'value' => (float) $r->value, 'recorded_at' => CarbonImmutable::parse($r->recorded_at, 'UTC')->toIso8601ZuluString()])->all();

        return ['latest' => $latest, 'series' => $series];
    }

    public static function present(IotDevice $d): array
    {
        return ['id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'kind' => $d->kind, 'plot_id' => $d->plot_id, 'location_id' => $d->location_id,
            'token_hint' => '…'.$d->token_hint, 'is_active' => $d->is_active, 'last_seen_at' => $d->last_seen_at?->toIso8601ZuluString(), 'version' => $d->version];
    }

    private function rules(): array
    {
        $farm = $this->context->farmId();

        return [
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(IotDevice::KINDS)],
            'plot_id' => ['nullable', 'uuid', Rule::exists('farm_plots', 'id')->where('farm_id', $farm)->whereNull('deleted_at')],
            'location_id' => ['nullable', 'uuid', Rule::exists('farm_locations', 'id')->where('farm_id', $farm)->whereNull('deleted_at')],
        ];
    }
}
