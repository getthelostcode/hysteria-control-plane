<?php

namespace Database\Factories;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    protected $model = Provider::class;

    public function definition(): array
    {
        return [
            'code' => 'code-' . Str::lower(Str::random(10)),
            'name' => fake()->company(),
            'contact' => fake()->safeEmail(),
            'status' => Provider::STATUS_ACTIVE,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => Provider::STATUS_SUSPENDED]);
    }
}
