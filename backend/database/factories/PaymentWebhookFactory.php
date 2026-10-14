<?php

namespace Database\Factories;

use App\Models\PaymentWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentWebhook>
 */
class PaymentWebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'mock',
            'external_event_id' => fake()->unique()->uuid(),
            'payload' => ['type' => 'payment.succeeded'],
            'processed_at' => now(),
            'status' => 'PROCESSED',
        ];
    }
}
