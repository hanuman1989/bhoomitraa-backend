<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive builder fields; existing rows keep their schedules and receive neutral defaults.
     */
    public function up(): void
    {
        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $table->string('schedule_type', 30)->nullable()->change();
            $table->text('timing_exception_reason')->nullable()->after('end_offset_days');
            $table->boolean('scheduling_reconfirmation_required')->default(false)->after('timing_exception_reason');
            $table->unsignedInteger('display_order')->default(0)->after('priority');
            $table->string('creation_request_id', 100)->nullable()->after('display_order');
            $table->unsignedInteger('version')->default(1)->after('creation_request_id');
            $table->unique('creation_request_id', 'uq_cat_creation_request');
        });
    }

    /**
     * Rollback is refused while pending drafts exist, because the legacy column cannot store them.
     */
    public function down(): void
    {
        if (DB::table('crop_activity_templates')->whereNull('schedule_type')->exists()) {
            throw new RuntimeException('Rollback aborted: pending crop activity template drafts have no schedule type. Configure their scheduling first.');
        }

        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $table->dropUnique('uq_cat_creation_request');
            $table->dropColumn([
                'timing_exception_reason', 'scheduling_reconfirmation_required',
                'display_order', 'creation_request_id', 'version',
            ]);
        });
        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $table->string('schedule_type', 30)->nullable(false)->change();
        });
    }
};
