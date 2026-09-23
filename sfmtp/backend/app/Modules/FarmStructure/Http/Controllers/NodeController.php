<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Modules\FarmStructure\Application\StructureService;
use App\Modules\FarmStructure\Application\StructureVisibility;
use App\Modules\FarmStructure\Domain\Enums\Irrigation;
use App\Modules\FarmStructure\Domain\Enums\LandUse;
use App\Modules\FarmStructure\Domain\Enums\LocationKind;
use App\Modules\FarmStructure\Domain\Geometry;
use App\Modules\FarmStructure\Domain\Models\StructureNode;
use App\Modules\FarmStructure\Http\Resources\StructureNodeResource;
use App\Support\Http\ApiException;
use App\Support\Http\OptimisticLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * CRUD for blocks, sections, plots and locations. Each route passes the
 * node type as a route default (`type`), and the record is bound by the
 * route parameter of the same name through the farm scope.
 */
class NodeController
{
    public function __construct(
        private readonly StructureService $structure,
        private readonly StructureVisibility $visibility,
    ) {}

    public function show(Request $request): StructureNodeResource
    {
        return new StructureNodeResource($this->node($request));
    }

    public function store(Request $request): JsonResponse
    {
        $type = $this->type($request);
        [$node, $warnings] = $this->structure->create($type, $this->validated($request, $type, creating: true));

        $plural = $type.'s';

        return (new StructureNodeResource($node))
            ->additional(['meta' => ['warnings' => $warnings]])
            ->response()->setStatusCode(201)
            ->header('Location', url("/api/v1/farms/{$node->farm_id}/structure/{$plural}/{$node->id}"));
    }

    public function update(Request $request): StructureNodeResource
    {
        $node = $this->node($request);
        OptimisticLock::check($request, $node);

        [$node, $warnings] = $this->structure->update($node, $this->validated($request, $node->nodeType(), creating: false));

        return (new StructureNodeResource($node))->additional(['meta' => ['warnings' => $warnings]]);
    }

    public function destroy(Request $request): Response
    {
        $this->structure->archive($this->node($request));

        return response()->noContent();
    }

    private function type(Request $request): string
    {
        return $request->route()->defaults['type'];
    }

    private function node(Request $request): StructureNode
    {
        $node = $request->route($this->type($request));
        if (! $node instanceof StructureNode || ! $this->visibility->canSee($node)) {
            throw ApiException::notFound();
        }

        return $node;
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, string $type, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'min:1', 'max:120'],
            'code' => ['sometimes', $creating ? 'nullable' : 'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'declared_area_ha' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999999'],
            'boundary' => ['sometimes', 'nullable', 'array'],
        ] + match ($type) {
            'section' => ['block_id' => [$required, 'uuid']],
            'plot' => [
                'section_id' => ['sometimes', 'nullable', 'uuid'],
                'land_use' => ['sometimes', Rule::in(LandUse::values())],
                'irrigation' => ['sometimes', Rule::in(Irrigation::values())],
            ],
            'location' => [
                'kind' => [$required, Rule::in(LocationKind::values())],
                'plot_id' => ['sometimes', 'nullable', 'uuid'],
                'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
                'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            ],
            default => [],
        };

        $data = $request->validate($rules);

        // Validate the raw body: the validator drops unlisted nested keys.
        if (array_key_exists('boundary', $data) && $data['boundary'] !== null) {
            $data['boundary'] = $request->input('boundary');
            if ($errors = Geometry::errors($data['boundary'])) {
                throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['boundary' => $errors]);
            }
        }

        return $data;
    }
}
