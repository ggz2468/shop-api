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
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropUnique('payment_transactions_merchant_trade_no_unique');

            $table->timestamp('expires_at')->nullable()->after('response_payload')->comment('付款交易過期時間');
            $table->timestamp('canceled_at')->nullable()->after('failed_at')->comment('付款取消時間');

            $table->unique(['provider', 'merchant_trade_no'], 'payment_transactions_provider_merchant_trade_unique');
            $table->index(['status', 'expires_at'], 'payment_transactions_status_expires_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('payment_transactions_status_expires_at_index');
            $table->dropUnique('payment_transactions_provider_merchant_trade_unique');

            $table->dropColumn(['expires_at', 'canceled_at']);

            $table->unique('merchant_trade_no', 'payment_transactions_merchant_trade_no_unique');
        });
    }
};
