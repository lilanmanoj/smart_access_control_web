<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An IANA timezone (e.g. Asia/Colombo) on every tenant and every device.
 *
 * Required by the dashboard API whenever a tenant or device is created, and
 * whenever an edit sends it. Nullable in the database only so rows created
 * before this existed stay valid: the dashboard asks for a timezone the next
 * time one of them is edited, defaulting a device to its tenant's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('status');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->string('timezone', 64)->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
