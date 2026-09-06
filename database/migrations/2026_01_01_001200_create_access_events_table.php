<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core audit trail: every access attempt through every method, granted or
 * denied. Append-only — rows are never updated.
 *
 * `occurred_at` is assigned by the server on receipt. The device has no RTC
 * and its clock reads 1970 until NTP lands, so a device-supplied wall clock is
 * never authoritative; `uptime_ms` exists only to order events inside a batch
 * that was queued while the panel was offline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // Null for an unknown finger or an unrecognised code — a denial
            // still has to be recorded even when nobody can be attributed.
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();

            // fingerprint | otp | backup_code | admin_auth | remote
            $table->string('method', 24);

            // granted | denied
            $table->string('result', 16);

            // Machine-readable: matched, no_match, not_enrolled,
            // member_suspended, otp_expired, otp_mismatch, code_invalid,
            // code_expired, not_admin, backend_unreachable, rate_limited,
            // outside_schedule, device_suspended, remote_unlock, duplicate.
            $table->string('reason', 32);

            $table->timestamp('occurred_at');

            // Device uptime-derived; ordering within a batch only.
            $table->timestamp('device_reported_at')->nullable();
            $table->unsignedBigInteger('uptime_ms')->nullable();

            $table->unsignedSmallInteger('fingerprint_slot')->nullable();

            // AS608 match confidence.
            $table->unsignedSmallInteger('confidence')->nullable();

            $table->foreignId('otp_request_id')->nullable()->constrained('otp_requests')->nullOnDelete();
            $table->foreignId('backup_code_id')->nullable()->constrained('backup_codes')->nullOnDelete();

            // For method=remote: the operator who pressed the button.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Device-generated, unique per device. An offline panel will resend
            // its queue; replays are deduplicated on this.
            $table->string('idempotency_key', 128)->nullable();

            $table->json('metadata')->nullable();

            // Rows are append-only, so there is no updated_at to maintain.
            $table->timestamp('created_at')->nullable();

            $table->unique(['device_id', 'idempotency_key'], 'access_events_device_idempotency_unique');

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['device_id', 'occurred_at']);
            $table->index(['member_id', 'occurred_at']);
            $table->index(['tenant_id', 'result', 'occurred_at']);
            $table->index(['tenant_id', 'method', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};
