<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'OF-'.strtoupper((string) Str::ulid()),
            'customer_id' => User::factory(),
            'status' => 'PENDING_PAYMENT',
            'subtotal' => '100.00',
            'total' => '100.00',
            'currency' => 'IDR',
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
