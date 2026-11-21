<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last template set served to each panel, so the dashboard can say how
 * big it is and warn when it was cut to fit the sensor's capacity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('template_set_revision', 64)->nullable()->after('enrolled_count');
            $table->unsignedSmallInteger('template_set_total')->nullable()->after('template_set_revision');

            // More members qualified than the sensor holds.
            $table->boolean('template_set_truncated')->default(false)->after('template_set_total');

            // Members who fit but are waiting for a released slot to be
            // confirmed empty before they can be placed.
            $table->unsignedSmallInteger('template_set_deferred')->default(0)->after('template_set_truncated');

            $table->timestamp('template_set_served_at')->nullable()->after('template_set_deferred');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'template_set_revision',
                'template_set_total',
                'template_set_truncated',
                'template_set_deferred',
                'template_set_served_at',
            ]);
        });
    }
};
