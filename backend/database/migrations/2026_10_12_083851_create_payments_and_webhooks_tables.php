<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('payment_reference', 64)->unique();
            $table->string('provider', 32);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 16)->default('PENDING')->index();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('payment_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('external_event_id', 128);
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->string('status', 16)->default('RECEIVED')->index();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['provider', 'external_event_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('PENDING', 'PROCESSING', 'SUCCEEDED', 'FAILED', 'EXPIRED', 'REFUNDED'))");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
            DB::statement("ALTER TABLE payment_webhooks ADD CONSTRAINT payment_webhooks_status_valid CHECK (status IN ('RECEIVED', 'PROCESSED', 'IGNORED', 'FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('payments');
    }
};
