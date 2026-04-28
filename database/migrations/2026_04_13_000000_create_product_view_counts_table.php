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
        Schema::create('product_view_counts', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('view_counts')->default(0)->comment('被瀏覽次數');
            $table->dateTime('recorded_at')->comment('紀錄時間');
            $table->primary(['product_id', 'recorded_at']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $thisMonthStart = new DateTimeImmutable('first day of this month 00:00:00');
        $nextMonthStart = $thisMonthStart->modify('+1 month');
        $currentUpperBound = $nextMonthStart->format('Y-m-d H:i:s');
        $currentPartition = 'p' . $thisMonthStart->format('Ym');

        $sqlStatement = <<< SQL_STATEMENT
        ALTER TABLE product_view_counts
        PARTITION BY RANGE COLUMNS(recorded_at) (
            PARTITION {$currentPartition} VALUES LESS THAN ('{$currentUpperBound}'),
            PARTITION pmax VALUES LESS THAN (MAXVALUE)
        )
        SQL_STATEMENT;

        DB::statement($sqlStatement);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_view_counts');
    }
};
