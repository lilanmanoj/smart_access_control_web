<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The device holds a set of five codes. Using any one of them supersedes the
 * whole set and a fresh set of five is issued — modelled as a new row rather
 * than by mutating the old one, so the history stays auditable.
 *
 * Backup codes are device-scoped, matching the firmware: five codes that open
 * *this door*, tied to no one. See open question 1 in the requirements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_code_sets', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            $table->string('status')->default('active'); // active | superseded
            $table->timestamp('issued_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 48)->default('initial'); // initial | used | rotated | expired
            $table->timestamps();

            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_code_sets');
    }
};
