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
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('member_id')->constrained('members');
            $table->string('number', 32)->unique()->comment('編號');
            $table->string('idempotency_key', 64)->unique()->comment('幂等鍵');
            $table->unsignedMediumInteger('total_amount')->comment('總計');
            $table->unsignedMediumInteger('tax_amount')->comment('稅額');
            $table->unsignedSmallInteger('shipping_fee')->comment('運費');
            $table->unsignedTinyInteger('status')->comment('狀態');
            $table->unsignedTinyInteger('payment_method')->nullable()->default(null)->comment('付款方式');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('deleted_at')->nullable();
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