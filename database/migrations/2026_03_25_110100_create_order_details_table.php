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
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('product_id')->constrained('products');
            $table->string('product_name', 50)->comment('產品名稱');
            $table->unsignedMediumInteger('product_price')->comment('產品價格');
            $table->unsignedTinyInteger('quantity')->comment('購買數量');
            $table->unsignedMediumInteger('subtotal')->comment('小計');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_details');
    }
};