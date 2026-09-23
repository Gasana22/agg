<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'user_type' => UserType::Member,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ];
    }

    public function platformAdmin(): static
    {
        return $this->state(['user_type' => UserType::PlatformAdmin]);
    }

    public function party(): static
    {
        return $this->state(['user_type' => UserType::Party]);
    }

    public function disabled(): static
    {
        return $this->state(['status' => UserStatus::Disabled]);
    }
}
