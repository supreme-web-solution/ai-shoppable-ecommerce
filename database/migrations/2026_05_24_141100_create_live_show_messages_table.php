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
        Schema::create('live_show_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_show_id')->constrained()->cascadeOnDelete();
            $table->foreignId('live_show_registration_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('sender_type', ['host', 'attendee', 'ai', 'system'])->default('attendee');
            $table->string('sender_name')->nullable();
            $table->text('message');
            $table->boolean('is_pinned')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['live_show_id', 'created_at']);
            $table->index(['live_show_id', 'sender_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_show_messages');
    }
};
