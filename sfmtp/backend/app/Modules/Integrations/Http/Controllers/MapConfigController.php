<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Modules\Integrations\Maps\MapTiles;
use Illuminate\Http\JsonResponse;

/** The base map every web and mobile map draws (ADR-0018). */
class MapConfigController
{
    public function __invoke(MapTiles $tiles): JsonResponse
    {
        return new JsonResponse(['data' => $tiles->config()]);
    }
}
