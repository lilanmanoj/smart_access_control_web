<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device API 1.3.0: `GET /backup-codes` re-serves the device's current set
 * instead of minting one per call, so the panel can sync the set an operator
 * rotated.
 *
 * - `codes_encrypted` holds the current set's plaintext, encrypted with the
 *   application key. It is cleared the moment the set is superseded or
 *   retired; the hashes in `backup_codes` remain the record.
 * - `fetched_at` (existing) now means "first collected by the device";
 *   `last_fetched_at` is the most recent collection.
 *
 * Sets issued before this migration have no plaintext. The first fetch after
 * deploy replaces such a set, which costs nothing: under the old behaviour
 * every fetch minted a set no operator had seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_code_sets', function (Blueprint $table) {
            $table->text('codes_encrypted')->nullable()->after('reason');
            $table->timestamp('last_fetched_at')->nullable()->after('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('backup_code_sets', function (Blueprint $table) {
            $table->dropColumn(['codes_encrypted', 'last_fetched_at']);
        });
    }
};
