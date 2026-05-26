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
        Schema::create('live_show_products', function (Blueprint $table) {
            $table->foreignId('live_show_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('starts_at_ms')->nullable();
            $table->unsignedInteger('ends_at_ms')->nullable();
            $table->unsignedInteger('pin_order')->default(0);
            $table->decimal('flash_discount', 5, 2)->nullable();
            $table->timestamps();

            $table->primary(['live_show_id', 'product_id']);
            $table->index(['live_show_id', 'pin_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('live_show_products');
    }
};
