<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Keeps packages/api-contracts/openapi.yaml honest: every /api/v1 route must
 * be documented, and every documented operation must exist.
 */
class OpenApiCoverageTest extends TestCase
{
    public function test_every_api_route_is_documented_and_vice_versa(): void
    {
        $spec = Yaml::parseFile(base_path('../packages/api-contracts/openapi.yaml'));

        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if ($method !== 'parameters') {
                    $documented[] = strtoupper($method).' '.$path;
                }
            }
        }

        $actual = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = substr($route->uri(), strlen('api/v1'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' '.$path;
            }
        }

        sort($documented);
        sort($actual);

        $this->assertSame([], array_values(array_diff($actual, $documented)), 'Routes missing from openapi.yaml');
        $this->assertSame([], array_values(array_diff($documented, $actual)), 'openapi.yaml documents routes that do not exist');
    }
}
