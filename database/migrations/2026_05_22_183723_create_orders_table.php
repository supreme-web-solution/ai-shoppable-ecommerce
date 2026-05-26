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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cart_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number')->unique();
            $table->enum('status', ['pending', 'paid', 'failed', 'cancelled', 'refunded'])->default('pending');
            $table->enum('checkout_mode', ['native', 'external', 'hybrid'])->default('hybrid');
            $table->enum('external_provider', ['none', 'shopify', 'woocommerce'])->default('none');
            $table->string('payment_reference')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->decimal('subtotal_amount', 10, 2)->default(0);
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('ordered_at');
            $table->timestamps();

            $table->index(['team_id', 'status', 'ordered_at']);
            $table->index(['team_id', 'external_provider', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
