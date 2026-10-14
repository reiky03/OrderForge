<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('key');
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->string('status', 16)->default('PROCESSING');
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();
            $table->unique(['user_id', 'key']);
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 128);
            $table->string('event_type', 80);
            $table->jsonb('payload');
            $table->string('status', 16)->default('PENDING')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at')->index();
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['status', 'available_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_valid CHECK (status IN ('PROCESSING', 'COMPLETED'))");
            DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_status_valid CHECK (status IN ('PENDING', 'PROCESSING', 'PUBLISHED', 'FAILED'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_keys');
    }
};
