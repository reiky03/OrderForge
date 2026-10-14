<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            ['OF-KB-001', 'Mechanical Keyboard', 'Hot-swappable mechanical keyboard', 899000],
            ['OF-MS-001', 'Wireless Mouse', 'Wireless ergonomic mouse', 349000],
            ['OF-HB-001', 'USB-C Hub', 'Seven-port USB-C hub', 599000],
            ['OF-MN-001', '27-inch Monitor', '27-inch QHD IPS monitor', 3299000],
            ['OF-ST-001', 'Laptop Stand', 'Adjustable aluminum laptop stand', 449000],
            ['OF-WC-001', 'Webcam', '1080p USB webcam', 699000],
            ['OF-HS-001', 'Headset', 'Wireless over-ear headset', 1199000],
            ['OF-SSD-1T', 'SSD 1TB', 'NVMe solid-state drive, 1TB', 1499000],
            ['OF-MP-001', 'Mouse Pad', 'Extended desk mouse pad', 199000],
            ['OF-MC-001', 'USB Microphone', 'USB condenser microphone', 999000],
            ['OF-DO-001', 'Docking Station', 'USB-C dual-display docking station', 1899000],
            ['OF-CH-001', 'Office Chair', 'Ergonomic mesh office chair', 2799000],
            ['OF-LT-001', 'Desk Lamp', 'Dimmable LED desk lamp', 399000],
            ['OF-CA-001', 'USB-C Cable', 'Two-meter braided USB-C cable', 99000],
            ['OF-KC-001', 'Keyboard Wrist Rest', 'Memory foam keyboard wrist rest', 159000],
            ['OF-MA-001', 'Monitor Arm', 'Single monitor desk arm', 799000],
            ['OF-RB-001', 'Router', 'Dual-band Wi-Fi 6 router', 1099000],
            ['OF-PO-001', 'Portable SSD', 'Portable SSD, 2TB', 2299000],
            ['OF-AD-001', 'Power Adapter', '65W USB-C power adapter', 299000],
            ['OF-SP-001', 'Desktop Speakers', 'Compact stereo desktop speakers', 549000],
        ];

        foreach ($products as [$sku, $name, $description, $price]) {
            Product::query()->firstOrCreate(
                ['sku' => $sku],
                ['name' => $name, 'description' => $description, 'price' => $price, 'currency' => 'IDR', 'status' => 'ACTIVE'],
            );
        }
    }
}
