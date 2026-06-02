<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_product_tags', function (Blueprint $table) {
            $table->string('overlay_kind', 32)->default('product')->after('position');
            $table->string('coupon_code', 64)->nullable()->after('overlay_kind');
        });
    }

    public function down(): void
    {
        Schema::table('video_product_tags', function (Blueprint $table) {
            $table->dropColumn(['overlay_kind', 'coupon_code']);
        });
    }
};
