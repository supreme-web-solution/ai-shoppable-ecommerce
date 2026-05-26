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
        Schema::create('reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id')->nullable();
            $table->string('emoji', 32)->default('like');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('reacted_at');
            $table->timestamps();

            $table->index(['video_id', 'emoji', 'reacted_at']);
            $table->index(['team_id', 'video_id']);
            $table->unique(['video_id', 'session_id', 'emoji'], 'reactions_unique_by_session');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reactions');
    }
};
