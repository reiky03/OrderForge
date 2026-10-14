<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 24)->default('PENDING_PAYMENT')->index();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total', 12, 2);
            $table->char('currency', 3);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->index(['customer_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('sku_snapshot', 64);
            $table->string('name_snapshot', 160);
            $table->decimal('price_snapshot', 12, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('subtotal', 12, 2);
            $table->timestampsTz();
            $table->unique(['order_id', 'product_id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->index(['order_id', 'status']);
            $table->unique(['order_id', 'product_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('PENDING_PAYMENT', 'PAID', 'PROCESSING', 'COMPLETED', 'CANCELLED', 'EXPIRED'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_nonnegative CHECK (subtotal >= 0 AND total >= 0 AND total = subtotal)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_quantity_positive CHECK (quantity > 0)');
            DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT stock_reservations_status_valid CHECK (status IN ('ACTIVE', 'COMMITTED', 'RELEASED', 'EXPIRED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
