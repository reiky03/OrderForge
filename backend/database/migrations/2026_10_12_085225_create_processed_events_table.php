<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 80);
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_events');
    }
};
