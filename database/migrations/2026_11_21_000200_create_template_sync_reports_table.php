<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each template sync actually did, as the panel reported it
 * (`POST /fingerprint-template-syncs`).
 *
 * The validated body is kept whole in `report`; the columns beside it are the
 * ones the dashboard filters and sorts on. `drift` is the backend's own
 * comparison of the report's `slots_present` against its assignments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_sync_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            $table->string('revision', 128)->nullable();

            // completed | partial | aborted
            $table->string('status', 16);
            $table->string('abort_reason', 32)->nullable();
            $table->unsignedInteger('failed')->default(0);

            $table->json('report');

            // {missing_slots: int[], unknown_slots: int[]}, or null when the
            // report carried no usable `slots_present`.
            $table->json('drift')->nullable();

            // Server time. The panel has no clock.
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['device_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_sync_reports');
    }
};
