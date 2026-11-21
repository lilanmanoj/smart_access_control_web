<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Member approval (manual §9.2): a tenant can require an operator to approve
 * a member enrolled at a panel before OTP and Admin Settings admit them.
 *
 * Approval is a timestamp rather than another `status` value on purpose. It is
 * orthogonal to suspension — an approved member can be suspended and back —
 * and every existing `status = active` check keeps meaning what it meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->timestamp('approved_at')->nullable()->after('is_admin');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'approved_at']);
        });

        // Everyone who exists already was admitted under the old rules;
        // switching approval on later must not lock them out retroactively.
        DB::table('members')->whereNull('approved_at')->update([
            'approved_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'approved_at']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
    }
};
