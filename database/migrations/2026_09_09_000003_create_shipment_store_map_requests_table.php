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
        Schema::create('shipment_store_map_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->unsignedTinyInteger('provider')->comment('物流服務商');
            $table->string('store_type', 16)->comment('超商類型');
            $table->string('merchant_trade_no', 64)->unique()->comment('商店交易編號');
            $table->string('selection_token', 64)->unique()->comment('門市選擇識別碼');
            $table->json('request_payload')->nullable()->comment('請求內容');
            $table->json('checkout_payload')->nullable()->comment('電子地圖提交資訊');
            $table->json('response_payload')->nullable()->comment('回應內容');
            $table->string('selected_store_code', 32)->nullable()->comment('已選擇超商門市代碼');
            $table->string('selected_store_name', 50)->nullable()->comment('已選擇超商門市名稱');
            $table->text('selected_store_address')->nullable()->comment('已選擇超商門市地址');
            $table->timestamp('expires_at')->nullable()->comment('過期時間');
            $table->timestamps();
            $table->index(['member_id', 'provider']);
            $table->index(['store_type', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipment_store_map_requests');
    }
};
