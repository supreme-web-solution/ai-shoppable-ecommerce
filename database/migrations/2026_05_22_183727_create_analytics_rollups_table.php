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
        Schema::create('analytics_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->date('metric_date');
            $table->string('metric_name');
            $table->unsignedBigInteger('value_unsigned')->default(0);
            $table->decimal('value_decimal', 14, 2)->default(0);
            $table->json('dimensions')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'video_id', 'metric_date', 'metric_name'], 'analytics_rollups_unique_metric');
            $table->index(['team_id', 'metric_date', 'metric_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_rollups');
    }
};
