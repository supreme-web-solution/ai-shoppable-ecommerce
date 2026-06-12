<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_show_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('starts_at_ms')->nullable()->change();
            $table->unsignedBigInteger('ends_at_ms')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('live_show_products', function (Blueprint $table): void {
            $table->unsignedInteger('starts_at_ms')->nullable()->change();
            $table->unsignedInteger('ends_at_ms')->nullable()->change();
        });
    }
};
