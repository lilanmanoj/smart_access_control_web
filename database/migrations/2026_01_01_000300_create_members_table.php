<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members are the people who walk through doors.
 *
 * `phone` is stored E.164-normalised and indexed: the OTP flow looks a member
 * up by the number typed on the panel, and device storage caps the number at
 * 14 characters, so anything longer never reaches this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('full_name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('active'); // active | suspended

            // Grants the device's Admin Settings flow. Not an access method:
            // it proves the holder may open the device's configuration portal.
            $table->boolean('is_admin')->default(false);

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A phone number identifies exactly one member within a tenant —
            // the OTP lookup depends on it being unambiguous.
            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'is_admin']);
            $table->index('full_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
