<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController
{
    public function __construct(private readonly CatalogService $catalogs) {}

    /** GET /catalog/{catalog} — active entries, for every signed-in user. */
    public function index(Request $request, string $catalog): JsonResponse
    {
        $data = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'filter.parent_id' => ['sometimes', 'uuid']]);

        return new JsonResponse(['data' => $this->catalogs->list($catalog, false, $data['q'] ?? null, $data['filter']['parent_id'] ?? null)]);
    }

    /** GET /admin/catalog/{catalog} — including inactive entries. */
    public function adminIndex(Request $request, string $catalog): JsonResponse
    {
        $data = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'filter.parent_id' => ['sometimes', 'uuid']]);

        return new JsonResponse(['data' => $this->catalogs->list($catalog, true, $data['q'] ?? null, $data['filter']['parent_id'] ?? null)]);
    }

    public function store(Request $request, string $catalog): JsonResponse
    {
        return new JsonResponse(['data' => $this->catalogs->create($catalog, $request->all())], 201);
    }

    public function update(Request $request, string $catalog, string $id): JsonResponse
    {
        return new JsonResponse(['data' => $this->catalogs->update($catalog, $id, $request->all())]);
    }
}
