<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The backend's copy of each fingerprint template (device API 1.1.0).
 *
 * From firmware 1.1 the panel reads the template back out of its sensor after
 * enrolment and sends it along. The sensor's copy is still the one that opens
 * the door; this one exists so a later version can restore approved members'
 * templates onto a panel. Nothing writes one back yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();

            // The panel whose sensor produced it, and the slot it sits in there.
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('fingerprint_slot');

            // The sync will refuse to push a template to a sensor of another
            // format, so both are kept even though only as608 exists today.
            $table->string('format', 16);
            $table->unsignedSmallInteger('size');

            // Of the plaintext, as the panel sent it. Integrity and sync
            // identity only: the same finger enrolled twice gives different
            // bytes, so this never identifies a person.
            $table->char('sha256', 64);

            // Application-level ciphertext (the `encrypted` cast). Nulled when
            // the slot erase is acknowledged: biometric data must not outlive
            // its enrolment.
            $table->text('data_encrypted')->nullable();

            // active | superseded | deleted
            $table->string('status')->default('active');

            // At most one active template per (device, slot). MySQL has no
            // partial indexes, so the slot is projected into a column that is
            // NULL unless the row is active, and NULLs are distinct in a
            // unique index.
            $table->unsignedSmallInteger('active_slot')
                ->nullable()
                ->storedAs("case when `status` = 'active' then `fingerprint_slot` end");

            $table->timestamps();

            $table->unique(['device_id', 'active_slot'], 'fingerprint_templates_device_active_slot_unique');
            $table->index(['device_id', 'fingerprint_slot', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fingerprint_templates');
    }
};
