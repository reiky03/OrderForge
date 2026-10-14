<?php

namespace App\Models;

use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    protected $fillable = ['code', 'name', 'location', 'status'];

    /** @return HasMany<Inventory, $this> */
    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
