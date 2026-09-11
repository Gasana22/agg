<?php

namespace App\Http\Controllers\Api\FarmStructure;

use App\Http\Controllers\Controller;
use App\Http\Requests\FarmStructure\StoreSectionRequest;
use App\Http\Requests\FarmStructure\UpdateSectionRequest;
use App\Http\Resources\SectionResource;
use App\Models\Block;
use App\Models\Section;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class SectionController extends Controller
{
    public function index(Block $block): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Section::class, $block]);

        return SectionResource::collection(
            $block->sections()->withCount('plots')->orderBy('name')->get()
        );
    }

    public function store(StoreSectionRequest $request, Block $block): SectionResource
    {
        $this->authorize('create', [Section::class, $block]);

        $section = $block->sections()->create([...$request->validated(), 'is_active' => true]);

        return new SectionResource($section->loadCount('plots'));
    }

    public function show(Section $section): SectionResource
    {
        $this->authorize('view', $section);

        return new SectionResource($section->loadCount('plots'));
    }

    public function update(UpdateSectionRequest $request, Section $section): SectionResource
    {
        $this->authorize('update', $section);

        $section->update($request->validated());

        return new SectionResource($section->loadCount('plots'));
    }

    public function destroy(Section $section): Response
    {
        $this->authorize('delete', $section);

        $section->delete();

        return response()->noContent();
    }
}
