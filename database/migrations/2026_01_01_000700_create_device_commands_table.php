<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbound command queue.
 *
 * The panel cannot receive pushes, so it polls. Without this table, revoking a
 * member would leave their finger working on the door.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // delete_enrollment | refresh_backup_codes | unlock | reboot
            // | update_settings | report_inventory
            $table->string('type', 48);
            $table->json('payload')->nullable();

            // pending | sent | acked | failed | expired
            $table->string('status')->default('pending');

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acked_at')->nullable();

            // A command the device never collected is expired rather than left
            // to fire later — a stale `unlock` opening a door is a real bug.
            $table->timestamp('expires_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
