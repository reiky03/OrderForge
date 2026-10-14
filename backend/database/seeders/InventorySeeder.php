<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $warehouses = Warehouse::query()->get()->keyBy('code');
        $stocks = [1, 18, 32, 6, 0, 14, 9, 27, 40, 11, 5, 19, 8, 50, 24, 7, 3, 16, 29, 12];

        foreach (Product::query()->orderBy('id')->get()->values() as $index => $product) {
            foreach (['JKT-01' => $stocks[$index] ?? 10, 'SBY-01' => $index === 0 ? 0 : (($index * 7) % 23)] as $code => $onHand) {
                $inventory = Inventory::query()->firstOrCreate(
                    ['product_id' => $product->id, 'warehouse_id' => $warehouses[$code]->id],
                    ['on_hand' => $onHand, 'reserved' => 0, 'version' => 0],
                );

                if ($inventory->wasRecentlyCreated && $onHand > 0) {
                    InventoryMovement::query()->create([
                        'inventory_id' => $inventory->id,
                        'type' => 'RESTOCK',
                        'quantity' => $onHand,
                        'reference_type' => 'seed',
                        'reference_id' => (string) $inventory->id,
                        'balance_after' => $onHand,
                        'reason' => 'Opening stock for local demo',
                    ]);
                }
            }
        }
    }
}
