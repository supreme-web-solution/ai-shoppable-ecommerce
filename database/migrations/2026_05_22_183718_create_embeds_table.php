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
        Schema::create('embeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->enum('type', ['vertical_feed', 'floating_widget', 'carousel', 'product_page'])->default('vertical_feed');
            $table->string('slug')->unique();
            $table->string('signed_key')->unique();
            $table->boolean('is_active')->default(true);
            $table->json('allowed_domains')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'is_active', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embeds');
    }
};
