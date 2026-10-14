<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name', 160)->index();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_nonnegative CHECK (price >= 0)');
            DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_valid CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
