<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive, nullable columns: existing crops keep working and simply have no timeline.
     */
    public function up(): void
    {
        Schema::table('farm_crop_cycles', function (Blueprint $table): void {
            $table->dateTime('timeline_generated_at', 6)->nullable()->after('finalized_at');
            $table->dateTime('timeline_alert_seen_at', 6)->nullable()->after('timeline_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('farm_crop_cycles', function (Blueprint $table): void {
            $table->dropColumn(['timeline_generated_at', 'timeline_alert_seen_at']);
        });
    }
};
