<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_type' => 'USER',
            'actor_id' => User::factory(),
            'action' => 'INVENTORY_ADJUSTED',
            'entity_type' => 'inventory',
            'entity_id' => (string) fake()->randomNumber(),
            'metadata' => ['quantity_delta' => 1],
            'request_id' => fake()->uuid(),
        ];
    }
}
