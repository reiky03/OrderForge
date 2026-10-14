<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['sku', 'name', 'description', 'price', 'currency', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    /** @return HasMany<Inventory, $this> */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /** @param Builder<Product> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'ACTIVE');
    }
}
