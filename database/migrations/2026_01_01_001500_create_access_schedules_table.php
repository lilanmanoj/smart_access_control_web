<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Time-of-day / day-of-week access windows (§9.7).
 *
 * A member with no window rows is unrestricted; adding one restricts them to
 * the union of their windows. A schedule may be scoped to one device or apply
 * to every device in the tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_schedules', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('access_schedule_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_schedule_id')->constrained()->cascadeOnDelete();

            // ISO-8601 weekday: 1 = Monday .. 7 = Sunday.
            $table->unsignedTinyInteger('weekday');

            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['access_schedule_id', 'weekday']);
        });

        // A member may hold several schedules; the union admits them.
        Schema::create('access_schedule_member', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // Null means the schedule applies on every device in the tenant.
            $table->foreignId('device_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['access_schedule_id', 'member_id', 'device_id'],
                'schedule_member_device_unique'
            );
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_schedule_member');
        Schema::dropIfExists('access_schedule_windows');
        Schema::dropIfExists('access_schedules');
    }
};
