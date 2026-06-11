<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->after('user_id');
            $table->index(['team_id', 'customer_email']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('full_name')->nullable();
            $table->string('source');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'email']);
            $table->index(['team_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'customer_email']);
            $table->dropColumn('customer_email');
        });
    }
};
