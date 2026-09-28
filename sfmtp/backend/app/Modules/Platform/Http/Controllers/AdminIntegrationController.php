<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Integrations;
use App\Modules\Platform\Application\IntegrationTester;
use App\Modules\Platform\Domain\Models\IntegrationProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AdminIntegrationController
{
    public function __construct(private readonly Integrations $integrations) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'data' => IntegrationProvider::orderBy('kind')->orderBy('provider')->get()->map(fn ($p) => $this->present($p))->all(),
            'meta' => ['providers' => Integrations::PROVIDERS],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(Integrations::PROVIDERS))],
            'provider' => ['required', 'string', Rule::in(Integrations::PROVIDERS[$request->input('kind')] ?? []),
                Rule::unique('integration_providers')->where('kind', $request->input('kind'))],
            'name' => ['required', 'string', 'max:100'],
            'config' => ['sometimes', 'array', 'max:30'],
            'config.*' => ['nullable', 'string', 'max:4000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);
        $config = array_filter($data['config'] ?? [], fn ($v) => $v !== null);
        $provider = $this->integrations->create(['config' => $config] + $data);

        return new JsonResponse(['data' => $this->present($provider)], 201);
    }

    public function update(Request $request, IntegrationProvider $integration): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'config' => ['sometimes', 'array', 'max:30'],
            'config.*' => ['nullable', 'string', 'max:4000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        return new JsonResponse(['data' => $this->present($this->integrations->update($integration, $data))]);
    }

    public function test(Request $request, IntegrationProvider $integration, IntegrationTester $tester): JsonResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:30']]);
        $result = $tester->test($integration, $request->user(), $data['phone'] ?? null);

        return new JsonResponse(['data' => $result + ['provider' => $this->present($integration->refresh())]]);
    }

    public function destroy(IntegrationProvider $integration): Response
    {
        $this->integrations->delete($integration);

        return response()->noContent();
    }

    private function present(IntegrationProvider $p): array
    {
        return [
            'id' => $p->id,
            'type' => 'integration',
            'kind' => $p->kind,
            'provider' => $p->provider,
            'name' => $p->name,
            'config' => Integrations::maskedConfig($p),
            'is_enabled' => $p->is_enabled,
            'is_default' => $p->is_default,
            'priority' => (int) $p->priority,
            'health' => [
                'status' => $p->consecutive_failures >= 3 ? 'down' : ($p->consecutive_failures > 0 ? 'degraded' : ($p->last_success_at ? 'ok' : 'unknown')),
                'last_success_at' => $p->last_success_at?->toIso8601ZuluString(),
                'last_failure_at' => $p->last_failure_at?->toIso8601ZuluString(),
                'last_error' => $p->last_error,
                'consecutive_failures' => (int) $p->consecutive_failures,
            ],
            'updated_at' => $p->updated_at?->toIso8601ZuluString(),
        ];
    }
}
