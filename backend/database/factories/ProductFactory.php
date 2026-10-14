<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-#####')),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 1, 10000000),
            'currency' => 'IDR',
            'status' => 'ACTIVE',
        ];
    }
}
