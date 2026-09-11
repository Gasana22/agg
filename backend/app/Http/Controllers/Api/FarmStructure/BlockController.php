<?php

namespace App\Http\Controllers\Api\FarmStructure;

use App\Http\Controllers\Controller;
use App\Http\Requests\FarmStructure\StoreBlockRequest;
use App\Http\Requests\FarmStructure\UpdateBlockRequest;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use App\Models\Farm;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class BlockController extends Controller
{
    public function index(Farm $farm): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Block::class, $farm]);

        return BlockResource::collection(
            $farm->blocks()->withCount('sections')->orderBy('name')->get()
        );
    }

    public function store(StoreBlockRequest $request, Farm $farm): BlockResource
    {
        $this->authorize('create', [Block::class, $farm]);

        $block = $farm->blocks()->create([...$request->validated(), 'is_active' => true]);

        return new BlockResource($block->loadCount('sections'));
    }

    public function show(Block $block): BlockResource
    {
        $this->authorize('view', $block);

        return new BlockResource($block->loadCount('sections'));
    }

    public function update(UpdateBlockRequest $request, Block $block): BlockResource
    {
        $this->authorize('update', $block);

        $block->update($request->validated());

        return new BlockResource($block->loadCount('sections'));
    }

    public function destroy(Block $block): Response
    {
        $this->authorize('delete', $block);

        $block->delete();

        return response()->noContent();
    }
}
