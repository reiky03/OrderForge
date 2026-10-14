<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $password = env('ORDERFORGE_DEMO_PASSWORD', 'OrderForgeLocal2026!');

        foreach ([
            ['Admin User', 'admin@orderforge.test', 'ADMIN'],
            ['Customer One', 'customer1@orderforge.test', 'CUSTOMER'],
            ['Customer Two', 'customer2@orderforge.test', 'CUSTOMER'],
        ] as [$name, $email, $role]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make($password)],
            );
            $user->forceFill(['name' => $name, 'role' => $role])->save();
        }

        $this->call([WarehouseSeeder::class, ProductSeeder::class, InventorySeeder::class]);
    }
}
