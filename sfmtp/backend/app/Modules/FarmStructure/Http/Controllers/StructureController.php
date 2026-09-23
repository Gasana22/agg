<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Modules\FarmStructure\Application\StructureVisibility;
use App\Modules\FarmStructure\Domain\Models\Block;
use App\Modules\FarmStructure\Domain\Models\Location;
use App\Modules\FarmStructure\Domain\Models\Plot;
use App\Modules\FarmStructure\Domain\Models\Section;
use App\Modules\FarmStructure\Http\Resources\StructureNodeResource;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The whole farm layout in one response, for the map and the tree view.
 * Lists are flat, each item carrying its parent id.
 */
class StructureController
{
    public function show(Request $request, StructureVisibility $visibility, TenantContext $context): JsonResponse
    {
        $load = fn (string $class) => $visibility->apply($class::query())->orderBy('code')->get();

        $blocks = $load(Block::class);
        $sections = $load(Section::class);
        $plots = $load(Plot::class);
        $locations = $load(Location::class);

        $size = $context->farm()->size_ha;

        return new JsonResponse(['data' => [
            'blocks' => StructureNodeResource::collection($blocks)->resolve($request),
            'sections' => StructureNodeResource::collection($sections)->resolve($request),
            'plots' => StructureNodeResource::collection($plots)->resolve($request),
            'locations' => StructureNodeResource::collection($locations)->resolve($request),
            'totals' => [
                'blocks' => $blocks->count(),
                'sections' => $sections->count(),
                'plots' => $plots->count(),
                'locations' => $locations->count(),
                'plots_mapped' => $plots->whereNotNull('boundary')->count(),
                'mapped_area_ha' => round($plots->sum(fn (Plot $p) => (float) $p->area_ha), 4),
                'plot_area_ha' => round($plots->sum(fn (Plot $p) => $p->effectiveAreaHa() ?? 0), 4),
                'farm_size_ha' => $size === null ? null : (float) $size,
            ],
        ]]);
    }
}
