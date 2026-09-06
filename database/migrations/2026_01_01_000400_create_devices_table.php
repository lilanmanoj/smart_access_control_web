<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The device-asserted identifier, generated on first boot as
            // SMA_<4 digits>. It is a claim to be verified against the API
            // credential, never trusted on its own.
            $table->string('device_id', 64);

            $table->string('name');
            $table->string('location')->nullable();

            // provisioned | active | suspended | retired
            $table->string('status')->default('provisioned');

            $table->string('firmware_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_health_at')->nullable();

            // True while the device is inside its health-poll window. Derived,
            // but stored so the dashboard can filter and sort on it.
            $table->boolean('is_online')->default(false);

            $table->unsignedSmallInteger('template_capacity')->default(200);
            $table->unsignedSmallInteger('enrolled_count')->default(0);

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'device_id']);
            $table->index(['tenant_id', 'status']);
            $table->index('last_health_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
