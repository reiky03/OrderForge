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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->integer('quantity');
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 128)->nullable();
            $table->integer('balance_after');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['inventory_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_quantity_nonzero CHECK (quantity <> 0)');
            DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_balance_nonnegative CHECK (balance_after >= 0)');
            DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_valid CHECK (type IN ('RESTOCK', 'RESERVE', 'RELEASE', 'SALE', 'ADJUSTMENT'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
