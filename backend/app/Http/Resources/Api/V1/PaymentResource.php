<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'payment_reference' => $this->payment_reference,
            'provider' => $this->provider,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
