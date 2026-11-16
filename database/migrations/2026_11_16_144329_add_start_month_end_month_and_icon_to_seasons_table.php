<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->unsignedTinyInteger('start_month')->nullable();
            $table->unsignedTinyInteger('end_month')->nullable();
            $table->string('icon', 30)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropColumn(['start_month', 'end_month', 'icon']);
        });
    }
};
