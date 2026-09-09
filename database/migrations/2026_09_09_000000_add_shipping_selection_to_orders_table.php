<?php

use App\Enums\Order\ShippingMethod;
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
            $table->unsignedTinyInteger('shipping_method')
                ->default(ShippingMethod::HOME_DELIVERY->value)
                ->after('shipping_fee')
                ->comment('配送方式');
            $table->string('store_code', 32)
                ->nullable()
                ->after('shipping_method')
                ->comment('超商門市代碼');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_method', 'store_code']);
        });
    }
};
