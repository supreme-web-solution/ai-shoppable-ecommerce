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
        Schema::create('live_show_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_show_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('email');
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('last_joined_at')->nullable();
            $table->unsignedInteger('join_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['live_show_id', 'email']);
            $table->index(['live_show_id', 'registered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_show_registrations');
    }
};
