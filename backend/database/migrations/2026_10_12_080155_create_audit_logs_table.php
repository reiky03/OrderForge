<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('entity_type', 80);
            $table->string('entity_id', 128);
            $table->jsonb('metadata');
            $table->uuid('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['actor_type', 'actor_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
