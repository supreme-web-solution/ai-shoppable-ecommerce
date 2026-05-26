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
        Schema::create('video_product_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('starts_at_ms');
            $table->unsignedInteger('ends_at_ms')->nullable();
            $table->string('cta_label')->nullable();
            $table->json('position')->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['video_id', 'product_id', 'starts_at_ms'], 'video_product_tags_unique_slot');
            $table->index(['video_id', 'is_pinned', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_product_tags');
    }
};
