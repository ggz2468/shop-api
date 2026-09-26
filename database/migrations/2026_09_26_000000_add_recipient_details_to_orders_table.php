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
            $table->string('recipient_name', 50)
                ->nullable()
                ->after('shipping_method')
                ->comment('收件人姓名');
            $table->string('recipient_phone', 20)
                ->nullable()
                ->after('recipient_name')
                ->comment('收件人電話');
            $table->text('recipient_address')
                ->nullable()
                ->after('recipient_phone')
                ->comment('收件地址');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'recipient_phone', 'recipient_address']);
        });
    }
};
