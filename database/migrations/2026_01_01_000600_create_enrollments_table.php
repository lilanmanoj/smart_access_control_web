<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An enrolment is the tuple (device, fingerprint slot) -> member.
 *
 * Templates live in the AS608's own flash and survive reboots and reflashes,
 * so the backend can never move one between devices — it can only record who
 * owns a slot, and ask the device to erase it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // Device-local slot id, 0 .. capacity-1. Null for a phone-only
            // member: the device reaches the enrolment screen with no template
            // when the user skips the finger or the registration rolled back.
            $table->unsignedSmallInteger('fingerprint_slot')->nullable();

            // pending  — created, awaiting a phone number
            // active   — usable
            // revoked  — withdrawn; the slot erase may still be queued
            // orphaned — backend and device disagree about the slot (§9.4)
            $table->string('status')->default('pending');

            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Two members must never claim the same slot on the same device.
            // MySQL treats NULLs as distinct in a unique index, so phone-only
            // enrolments (slot null) are unaffected; revoked rows are excluded
            // by nulling the slot on revocation.
            $table->unique(['device_id', 'fingerprint_slot'], 'enrollments_device_slot_unique');

            $table->index(['tenant_id', 'status']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
