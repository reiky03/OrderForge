<?php

namespace Database\Factories;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => fake()->uuid(),
            'request_hash' => hash('sha256', fake()->uuid()),
            'response_status' => null,
            'response_body' => null,
            'status' => 'PROCESSING',
            'expires_at' => now()->addDay(),
        ];
    }
}
