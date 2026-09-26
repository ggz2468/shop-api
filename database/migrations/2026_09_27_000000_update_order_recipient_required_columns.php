<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE orders
            INNER JOIN members ON orders.member_id = members.id
            SET orders.recipient_name = TRIM(CONCAT(COALESCE(members.last_name, ''), COALESCE(members.first_name, '')))
            WHERE orders.recipient_name IS NULL
              AND TRIM(CONCAT(COALESCE(members.last_name, ''), COALESCE(members.first_name, ''))) <> ''
        SQL);

        DB::statement(<<<'SQL'
            UPDATE orders
            INNER JOIN members ON orders.member_id = members.id
            SET orders.recipient_phone = members.phone
            WHERE orders.recipient_phone IS NULL
        SQL);

        DB::table('orders')
            ->whereNull('recipient_name')
            ->update(['recipient_name' => '會員']);

        DB::table('orders')
            ->whereNull('recipient_phone')
            ->update(['recipient_phone' => '未提供']);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('recipient_name', 50)
                ->nullable(false)
                ->comment('收件人姓名')
                ->change();
            $table->string('recipient_phone', 20)
                ->nullable(false)
                ->comment('收件人電話')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('recipient_name', 50)
                ->nullable()
                ->comment('收件人姓名')
                ->change();
            $table->string('recipient_phone', 20)
                ->nullable()
                ->comment('收件人電話')
                ->change();
        });
    }
};
