<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Health-poll history, so the device detail screen can show when a door went
 * dark rather than only that it currently is.
 *
 * One row per poll would be ~2,880 rows per device per day, so the recorder
 * collapses consecutive identical polls into a single row with a heartbeat
 * counter and only opens a new row on a state change or hourly rollover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_health_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            $table->string('state', 16); // online | offline
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('poll_count')->default(1);

            $table->string('firmware_version', 32)->nullable();
            $table->unsignedBigInteger('uptime_ms')->nullable();
            $table->unsignedSmallInteger('enrolled_count')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['device_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_health_reports');
    }
};
