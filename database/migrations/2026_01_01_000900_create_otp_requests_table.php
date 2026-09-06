<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time passwords.
 *
 * The code is only ever stored hashed. Under the secure contract the device
 * never sees the plaintext at all: the backend delivers it over SMS and
 * e-mail, and the device submits what the user typed for verification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();

            $table->string('phone', 20);
            $table->string('code_hash');

            // issued | delivered | verified | expired | failed
            $table->string('status')->default('issued');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();

            // Per-channel delivery status for SMS and e-mail. The two channels
            // are independent: e-mail is not a fallback triggered by an SMS
            // failure, because the device's SIM800L reports its status locally
            // and the backend never learns of it.
            $table->json('delivery')->nullable();

            $table->string('request_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['device_id', 'status']);
            $table->index(['phone', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_requests');
    }
};
