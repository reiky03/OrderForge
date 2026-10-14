<?php

namespace App\Models;

use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'warehouse_id', 'on_hand', 'reserved', 'version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['on_hand' => 'integer', 'reserved' => 'integer', 'version' => 'integer'];
    }

    /** @return Attribute<int, never> */
    protected function available(): Attribute
    {
        return Attribute::get(fn (): int => $this->on_hand - $this->reserved);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
