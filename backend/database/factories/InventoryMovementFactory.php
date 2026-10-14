<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inventory_id' => Inventory::factory(),
            'type' => 'RESTOCK',
            'quantity' => 25,
            'reference_type' => 'seed',
            'reference_id' => null,
            'balance_after' => 25,
            'reason' => 'Opening balance',
        ];
    }
}
