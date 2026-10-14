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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'product_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_on_hand_nonnegative CHECK (on_hand >= 0)');
            DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_reserved_nonnegative CHECK (reserved >= 0)');
            DB::statement('ALTER TABLE inventories ADD CONSTRAINT inventories_reserved_within_on_hand CHECK (reserved <= on_hand)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
