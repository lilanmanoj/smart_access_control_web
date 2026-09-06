<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_codes', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('set_id')->constrained('backup_code_sets')->cascadeOnDelete();

            $table->string('code_hash');

            // For display only — the dashboard shows the last four digits so an
            // operator can tell one code from another without revealing it.
            $table->string('last4', 4);

            $table->timestamp('used_at')->nullable();

            // Set once the corresponding access event has been written. The
            // foreign key is added after access_events exists.
            $table->unsignedBigInteger('used_event_id')->nullable();

            $table->timestamps();

            $table->index('set_id');
            $table->index('used_event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_codes');
    }
};
