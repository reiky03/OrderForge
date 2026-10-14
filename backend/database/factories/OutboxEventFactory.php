<?php

namespace Database\Factories;

use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'aggregate_type' => 'order',
            'aggregate_id' => (string) fake()->randomNumber(),
            'event_type' => 'order.created',
            'payload' => ['event_type' => 'order.created'],
            'status' => 'PENDING',
            'attempts' => 0,
            'available_at' => now(),
        ];
    }
}
