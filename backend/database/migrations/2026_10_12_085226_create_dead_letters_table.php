<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dead_letters', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 80);
            $table->jsonb('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure_reason');
            $table->string('status', 16)->default('OPEN')->index();
            $table->timestampTz('failed_at')->useCurrent();
            $table->timestampTz('retried_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letters');
    }
};
