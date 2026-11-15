<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('profile_image')->nullable();
            $table->foreignId('state_id')->nullable()->constrained('regions')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('regions')->restrictOnDelete();
            $table->string('village', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('pincode', 20)->nullable();
            $table->string('preferred_language', 12)->default('hi');
            $table->string('preferred_units', 20)->default('bigha');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_profiles');
    }
};
