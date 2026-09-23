<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Application\Integrations;
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
        ]);

        return new JsonResponse(['data' => $this->present($this->integrations->update($integration, $data))]);
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
            'updated_at' => $p->updated_at?->toIso8601ZuluString(),
        ];
    }
}
