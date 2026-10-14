<?php

namespace App\Models;

use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['inventory_id', 'type', 'quantity', 'reserved_delta', 'reference_type', 'reference_id', 'balance_after', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'reserved_delta' => 'integer', 'balance_after' => 'integer'];
    }

    /** @return BelongsTo<Inventory, $this> */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
