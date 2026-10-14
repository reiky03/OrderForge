<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('order_items', 'inventory_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropForeign('order_items_inventory_id_foreign');
                $table->dropColumn('inventory_id');
            });
        }

        if (! Schema::hasColumn('stock_reservations', 'inventory_id')) {
            Schema::table('stock_reservations', function (Blueprint $table): void {
                $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropForeign(['inventory_id']);
            $table->dropColumn('inventory_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('inventory_id')->nullable()->constrained()->nullOnDelete();
        });
    }
};
