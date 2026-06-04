<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_show_registrations', function (Blueprint $table) {
            $table->unsignedInteger('max_watch_ms')->default(0)->after('join_count');
            $table->timestamp('reached_half_at')->nullable()->after('max_watch_ms');
            $table->timestamp('watched_to_end_at')->nullable()->after('reached_half_at');

            $table->index(['live_show_id', 'reached_half_at']);
            $table->index(['live_show_id', 'watched_to_end_at']);
        });
    }

    public function down(): void
    {
        Schema::table('live_show_registrations', function (Blueprint $table) {
            $table->dropColumn(['max_watch_ms', 'reached_half_at', 'watched_to_end_at']);
        });
    }
};
