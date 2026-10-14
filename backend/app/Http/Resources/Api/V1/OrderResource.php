<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
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
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'total' => $this->total,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'items' => $this->items->map(fn (OrderItem $item): array => [
                'product_id' => $item->product_id,
                'sku' => $item->sku_snapshot,
                'name' => $item->name_snapshot,
                'unit_price' => $item->price_snapshot,
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
            ])->all(),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment === null ? null : PaymentResource::make($this->payment)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
