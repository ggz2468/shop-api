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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('store_type', 16)
                ->nullable()
                ->after('shipping_method')
                ->comment('超商類型');
            $table->string('store_name', 50)
                ->nullable()
                ->after('store_code')
                ->comment('超商門市名稱');
            $table->text('store_address')
                ->nullable()
                ->after('store_name')
                ->comment('超商門市地址');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['store_type', 'store_name', 'store_address']);
        });
    }
};
