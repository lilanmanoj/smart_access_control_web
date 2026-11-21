<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per-device slot assignments (device API 1.2.0).
 *
 * Since panels pull templates back, a member's slot is no longer one number:
 * they sit in the slot they enrolled into on their own panel, and in whatever
 * slot the backend assigned on every other panel. This table is that mapping,
 * and every `(device, fingerprint_slot) → member` lookup goes through it.
 *
 * A row is `active` while it is listed to the panel, and `releasing` once it
 * is not: the panel may still hold the finger until its next sync, so the slot
 * is not reusable until a sync report shows it empty, at which point the row
 * is deleted. There is no third state — a free slot is the absence of a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_template_slots', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // Device-local, 0 .. capacity-1 on that panel.
            $table->unsignedSmallInteger('slot');

            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // The enrolment behind an `enrolled_here` row. Null for `pushed`.
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();

            // The bytes to list for this slot. Null means the backend holds
            // none, which only an `enrolled_here` row may be listed with.
            $table->foreignId('fingerprint_template_id')->nullable()->constrained()->nullOnDelete();

            // enrolled_here | pushed
            $table->string('origin', 16);

            // active | releasing
            $table->string('status', 16)->default('active');

            // Set when a sync report's `slots_present` shows the panel holds it.
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            // Every row is active or releasing, so this is exactly "one owner
            // per slot among active and releasing rows".
            $table->unique(['device_id', 'slot'], 'device_template_slots_device_slot_unique');

            $table->index(['device_id', 'status']);
            $table->index(['member_id', 'status']);
            $table->index('enrollment_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('device_template_slots');
    }

    /**
     * Every enrolment that holds a slot today becomes that member's
     * `enrolled_here` assignment on its device, with its active template if we
     * hold one. Query builder only: models change, migrations must not.
     */
    private function backfill(): void
    {
        DB::table('enrollments')
            ->whereNotNull('fingerprint_slot')
            ->orderBy('id')
            ->chunkById(500, function ($enrollments): void {
                $templates = DB::table('fingerprint_templates')
                    ->whereIn('enrollment_id', $enrollments->pluck('id'))
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->pluck('id', 'enrollment_id');

                $now = now();

                DB::table('device_template_slots')->insert($enrollments->map(fn ($enrollment): array => [
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $enrollment->tenant_id,
                    'device_id' => $enrollment->device_id,
                    'slot' => $enrollment->fingerprint_slot,
                    'member_id' => $enrollment->member_id,
                    'enrollment_id' => $enrollment->id,
                    'fingerprint_template_id' => $templates[$enrollment->id] ?? null,
                    'origin' => 'enrolled_here',
                    'status' => 'active',
                    'confirmed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }
};
