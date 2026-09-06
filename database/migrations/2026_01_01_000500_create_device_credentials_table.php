<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Device API credentials. Two live rows per device are allowed so a secret can
 * be rotated without a window where the panel cannot reach the backend.
 *
 * The secret is only ever stored hashed; it is shown once, at creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credentials', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // Looked up on every device request, so it carries its own index.
            $table->string('api_key', 64)->unique();
            $table->string('api_secret_hash');

            // Kept for the dashboard's credential list; never enough to
            // reconstruct the secret.
            $table->string('secret_last4', 4)->nullable();

            $table->string('label')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credentials');
    }
};
