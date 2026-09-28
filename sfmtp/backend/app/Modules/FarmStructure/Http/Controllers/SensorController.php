<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Modules\FarmStructure\Application\Sensors;
use App\Modules\FarmStructure\Domain\Models\IotDevice;
use App\Support\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** IoT extension point: sensors and their readings (ADR-0018). */
class SensorController
{
    public function __construct(private readonly Sensors $sensors) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => IotDevice::orderBy('code')->get()->map(fn ($d) => Sensors::present($d))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        [$device, $token] = $this->sensors->register($request->all());

        return new JsonResponse(['data' => Sensors::present($device), 'meta' => ['token' => $token]], 201);
    }

    public function update(Request $request, string $farm, string $iotDevice): JsonResponse
    {
        return new JsonResponse(['data' => Sensors::present($this->sensors->update($this->device($iotDevice), $request->all()))]);
    }

    public function rotate(string $farm, string $iotDevice): JsonResponse
    {
        $device = $this->device($iotDevice);
        $token = $this->sensors->rotate($device);

        return new JsonResponse(['data' => Sensors::present($device->refresh()), 'meta' => ['token' => $token]]);
    }

    public function readings(Request $request, string $farm, string $iotDevice): JsonResponse
    {
        $device = $this->device($iotDevice);
        $data = $request->validate([
            'metric' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->utc() : CarbonImmutable::now()->subDays(7);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->utc()->endOfDay() : CarbonImmutable::now();

        return new JsonResponse(['data' => Sensors::present($device) + $this->sensors->readings($device, $data['metric'] ?? null, $from->toDateTimeString(), $to->format('Y-m-d H:i:s.u'))]);
    }

    /** Devices post here with `Authorization: Bearer sfmtpd_…`. */
    public function ingest(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        return new JsonResponse(['data' => ['stored' => $this->sensors->ingest($token && str_starts_with($token, 'sfmtpd_') ? $token : null, $request->all())]], 201);
    }

    private function device(string $id): IotDevice
    {
        return (Str::isUuid($id) ? IotDevice::find($id) : null) ?? throw ApiException::notFound();
    }
}
