<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crop_field_layouts', function (Blueprint $table): void {
            $table->json('wizard_draft')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crop_field_layouts', function (Blueprint $table): void {
            $table->dropColumn('wizard_draft');
        });
    }
};
