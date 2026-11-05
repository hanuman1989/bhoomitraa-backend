<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 500);
            $table->string('publisher');
            $table->string('source_type', 40);
            $table->string('source_url', 2048)->nullable();
            $table->string('language', 20)->nullable();
            $table->date('published_at')->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('reference_identifier')->nullable();
            $table->string('identifier_type', 30)->nullable();
            $table->text('description')->nullable();
            $table->text('license_notes')->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at', 6)->nullable();
            $table->dateTime('last_checked_at', 6)->nullable();
            $table->dateTime('next_review_at', 6)->nullable();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            /** The approved schema requires non-null DATETIME(6), rather than Laravel's nullable TIMESTAMP defaults. */
            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->foreign('verified_by', 'fk_ks_verified_by')->references('id')->on('admin_users')->nullOnDelete();
            $table->foreign('created_by', 'fk_ks_created_by')->references('id')->on('admin_users')->nullOnDelete();
            $table->foreign('updated_by', 'fk_ks_updated_by')->references('id')->on('admin_users')->nullOnDelete();
            $table->index('verification_status', 'idx_ks_verification_status');
            $table->index('source_type', 'idx_ks_source_type');
            $table->index(['is_active', 'verification_status'], 'idx_ks_active_status');
            $table->index('publisher', 'idx_ks_publisher');
            $table->index('next_review_at', 'idx_ks_review_due');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
    }
};
