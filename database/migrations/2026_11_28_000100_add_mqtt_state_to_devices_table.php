<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device API 1.4.0: what a panel last said about its MQTT doorbell.
 *
 * Reported on /health as `mqtt=1|0` and `mqtt_reconnects`. Null until a panel
 * that knows about the doorbell reports it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->boolean('mqtt_connected')->nullable()->after('is_online');
            $table->unsignedInteger('mqtt_reconnects')->nullable()->after('mqtt_connected');
            $table->timestamp('mqtt_reported_at')->nullable()->after('mqtt_reconnects');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['mqtt_connected', 'mqtt_reconnects', 'mqtt_reported_at']);
        });
    }
};
