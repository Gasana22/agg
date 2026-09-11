<?php

namespace App\Http\Controllers\Api\FarmStructure;

use App\Http\Controllers\Controller;
use App\Http\Requests\FarmStructure\StorePlotRequest;
use App\Http\Requests\FarmStructure\UpdatePlotRequest;
use App\Http\Resources\PlotResource;
use App\Models\Plot;
use App\Models\Section;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PlotController extends Controller
{
    public function index(Section $section): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Plot::class, $section]);

        return PlotResource::collection(
            $section->plots()->orderBy('name')->get()
        );
    }

    public function store(StorePlotRequest $request, Section $section): PlotResource
    {
        $this->authorize('create', [Plot::class, $section]);

        $plot = $section->plots()->create([...$request->validated(), 'is_active' => true]);

        return new PlotResource($plot);
    }

    public function show(Plot $plot): PlotResource
    {
        $this->authorize('view', $plot);

        return new PlotResource($plot);
    }

    public function update(UpdatePlotRequest $request, Plot $plot): PlotResource
    {
        $this->authorize('update', $plot);

        $plot->update($request->validated());

        return new PlotResource($plot);
    }

    public function destroy(Plot $plot): Response
    {
        $this->authorize('delete', $plot);

        $plot->delete();

        return response()->noContent();
    }
}
