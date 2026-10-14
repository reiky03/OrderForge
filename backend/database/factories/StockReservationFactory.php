<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'inventory_id' => Inventory::factory(),
            'product_id' => fn (array $attributes): int => Inventory::query()->findOrFail($attributes['inventory_id'])->product_id,
            'warehouse_id' => fn (array $attributes): int => Inventory::query()->findOrFail($attributes['inventory_id'])->warehouse_id,
            'quantity' => 1,
            'status' => 'ACTIVE',
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
