<?php

namespace App\Modules\FarmStructure\Http\Controllers;

use App\Modules\FarmStructure\Application\FarmWeather;
use Illuminate\Http\JsonResponse;

class WeatherController
{
    public function __invoke(FarmWeather $weather): JsonResponse
    {
        return new JsonResponse(['data' => $weather->forecast()]);
    }
}
