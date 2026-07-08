<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('zernio_account_id');
            $table->string('zernio_profile_id')->nullable();
            $table->string('platform_username')->nullable();
            $table->timestamp('connected_at')->useCurrent();
            $table->timestamps();

            $table->unique(['team_id', 'platform']);
            $table->index('zernio_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
