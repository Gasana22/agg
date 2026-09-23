<?php

namespace Database\Factories;

use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Only for attribute generation. Real farms are created through
 * FarmService::create(), which also creates the owner membership and roles.
 *
 * @extends Factory<Farm>
 */
class FarmFactory extends Factory
{
    protected $model = Farm::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Farm',
            'district' => fake()->randomElement(['Wakiso', 'Mukono', 'Mbarara', 'Gulu', 'Masaka']),
            'village' => fake()->city(),
            'size_ha' => fake()->randomFloat(2, 5, 500),
            'timezone' => 'Africa/Kampala',
            'currency' => 'UGX',
            'country' => 'UG',
        ];
    }
}
