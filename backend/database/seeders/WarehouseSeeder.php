<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([
            ['code' => 'JKT-01', 'name' => 'Jakarta Fulfillment Center', 'location' => 'Jakarta'],
            ['code' => 'SBY-01', 'name' => 'Surabaya Fulfillment Center', 'location' => 'Surabaya'],
        ] as $warehouse) {
            Warehouse::query()->firstOrCreate(
                ['code' => $warehouse['code']],
                [...$warehouse, 'status' => 'ACTIVE'],
            );
        }
    }
}
