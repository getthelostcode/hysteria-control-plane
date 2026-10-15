<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EnrollToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollToken>
 */
class EnrollTokenFactory extends Factory
{
    protected $model = EnrollToken::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', 'enr_' . fake()->unique()->uuid()),
            'prefix' => 'enr_' . fake()->bothify('????'),
            'note' => fake()->sentence(3),
            'max_uses' => 1,
            'uses' => 0,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (): array => ['uses' => 5, 'max_uses' => 5]);
    }
}
