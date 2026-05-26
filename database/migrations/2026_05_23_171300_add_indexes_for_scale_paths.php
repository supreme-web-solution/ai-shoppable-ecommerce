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
        Schema::table('videos', function (Blueprint $table): void {
            $table->index('cloudinary_public_id');
        });

        Schema::table('viewer_sessions', function (Blueprint $table): void {
            $table->index('last_seen_at');
            $table->unique(['team_id', 'video_id', 'session_key']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->unique(['team_id', 'source', 'external_id']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->unique(['team_id', 'session_key', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table): void {
            $table->dropUnique('carts_team_id_session_key_status_unique');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique('products_team_id_source_external_id_unique');
        });

        Schema::table('viewer_sessions', function (Blueprint $table): void {
            $table->dropUnique('viewer_sessions_team_id_video_id_session_key_unique');
            $table->dropIndex('viewer_sessions_last_seen_at_index');
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex('videos_cloudinary_public_id_index');
        });
    }
};
