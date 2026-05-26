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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('source', ['uploaded', 'ai_generated', 'live_replay'])->default('uploaded');
            $table->enum('status', ['draft', 'processing', 'ready', 'published', 'failed'])->default('draft');
            $table->enum('visibility', ['public', 'unlisted', 'private'])->default('private');
            $table->string('cloudinary_public_id')->nullable();
            $table->text('playback_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status', 'published_at']);
            $table->index(['team_id', 'source', 'visibility']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
