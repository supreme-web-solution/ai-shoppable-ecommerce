<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_show_view_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_show_id')->constrained()->cascadeOnDelete();
            $table->string('viewer_key', 80);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['live_show_id', 'viewer_key']);
            $table->index(['live_show_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_show_view_sessions');
    }
};
