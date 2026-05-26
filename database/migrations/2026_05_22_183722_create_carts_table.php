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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_key');
            $table->enum('status', ['active', 'checked_out', 'abandoned'])->default('active');
            $table->enum('checkout_mode', ['native', 'external', 'hybrid'])->default('hybrid');
            $table->enum('external_provider', ['none', 'shopify', 'woocommerce'])->default('none');
            $table->char('currency', 3)->default('USD');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status', 'updated_at']);
            $table->index(['team_id', 'session_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
