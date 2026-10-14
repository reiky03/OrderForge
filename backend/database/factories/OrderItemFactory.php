<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
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
            'product_id' => Product::factory(),
            'sku_snapshot' => fake()->unique()->bothify('SKU-#####'),
            'name_snapshot' => fake()->words(3, true),
            'price_snapshot' => '100.00',
            'quantity' => 1,
            'subtotal' => '100.00',
        ];
    }
}
