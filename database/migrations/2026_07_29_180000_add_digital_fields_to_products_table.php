<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('product_type', 20)->default('physical')->after('source');
            $table->string('digital_access_type', 20)->nullable()->after('product_type');
            $table->text('digital_access_url')->nullable()->after('digital_access_type');
            $table->string('digital_file_name')->nullable()->after('digital_access_url');

            $table->index(['team_id', 'product_type']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'product_type']);
            $table->dropColumn([
                'product_type',
                'digital_access_type',
                'digital_access_url',
                'digital_file_name',
            ]);
        });
    }
};
