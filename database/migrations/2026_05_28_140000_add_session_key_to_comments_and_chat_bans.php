<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->string('session_key', 255)->nullable()->after('user_id');
            $table->index(['video_id', 'session_key']);
        });

        DB::table('comments')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(200, function ($comments): void {
                foreach ($comments as $comment) {
                    $metadata = json_decode($comment->metadata ?? '[]', true);
                    $sessionKey = is_array($metadata)
                        ? trim((string) ($metadata['session_key'] ?? ''))
                        : '';

                    if ($sessionKey !== '') {
                        DB::table('comments')
                            ->where('id', $comment->id)
                            ->update(['session_key' => $sessionKey]);
                    }
                }
            });

        Schema::create('chat_session_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('session_key', 255);
            $table->foreignId('banned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['video_id', 'session_key']);
            $table->index(['team_id', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_session_bans');

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex(['video_id', 'session_key']);
            $table->dropColumn('session_key');
        });
    }
};
