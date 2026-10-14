<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Inventory */
class InventoryResource extends JsonResource
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
            'product' => ['id' => $this->product->id, 'sku' => $this->product->sku, 'name' => $this->product->name],
            'warehouse' => ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name],
            'on_hand' => $this->on_hand,
            'reserved' => $this->reserved,
            'available' => $this->available,
            'version' => $this->version,
        ];
    }
}
