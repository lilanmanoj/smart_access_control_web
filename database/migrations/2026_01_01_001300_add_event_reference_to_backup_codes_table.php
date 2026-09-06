<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * backup_codes.used_event_id references access_events, and access_events
 * references backup_codes. The cycle is broken by adding this constraint once
 * both tables exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_codes', function (Blueprint $table) {
            $table->foreign('used_event_id')
                ->references('id')
                ->on('access_events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backup_codes', function (Blueprint $table) {
            $table->dropForeign(['used_event_id']);
        });
    }
};
