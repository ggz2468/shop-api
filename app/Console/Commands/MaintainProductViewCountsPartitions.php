<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

class MaintainProductViewCountsPartitions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:maintain-product-view-counts-partitions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '維護產品被瀏覽次數資料表的分區';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $driver = DB::connection()->getDriverName();

        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->info('Skip partition maintenance: current driver does not support this workflow.');
            return self::SUCCESS;
        }

        // 定義下一個月的分區的所需資訊
        $thisMonthStart = new DateTimeImmutable('first day of this month 00:00:00');
        $nextMonthStart = $thisMonthStart->modify('+1 month');
        $nextNextMonthStart = $thisMonthStart->modify('+2 months');
        $nextUpperBound = $nextNextMonthStart->format('Y-m-d H:i:s');
        $nextPartition = 'p' . $nextMonthStart->format('Ym');
        $pmaxExists = DB::table('information_schema.partitions')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', 'product_view_counts')
            ->where('partition_name', 'pmax')
            ->exists();

        // 確認 pmax 分區是否存在，若不存在則無法進行分區維護
        if (!$pmaxExists) {
            $this->error('Partition pmax does not exist on product_view_counts.');
            return self::FAILURE;
        }

        $nextPartitionExists = DB::table('information_schema.partitions')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_name', 'product_view_counts')
            ->where('partition_name', $nextPartition)
            ->exists();

        // 確認下一個月的分區是否存在，若不存在則新增該分區
        if (!$nextPartitionExists) {
            $sqlStatement = <<< SQL_STATEMENT
            ALTER TABLE product_view_counts
            REORGANIZE PARTITION pmax INTO (
                PARTITION {$nextPartition} VALUES LESS THAN ('{$nextUpperBound}'),
                PARTITION pmax VALUES LESS THAN (MAXVALUE)
            )
            SQL_STATEMENT;
            DB::statement($sqlStatement);
        }

        // 取得超過三個月前的所有月分區（僅處理 pYYYYMM，不包含 pmax）
        $cutoffMonth = $thisMonthStart->modify('-3 months')->format('Ym');
        $partitions = DB::select(
            'SELECT partition_name AS partition_name FROM information_schema.partitions WHERE table_schema = DATABASE() AND table_name = ? AND partition_name IS NOT NULL',
            ['product_view_counts']
        );

        $expiredPartitions = [];

        foreach ($partitions as $partition) {
            $partitionName = $partition->partition_name ?? $partition->PARTITION_NAME ?? null;

            if (!is_string($partitionName)) {
                continue;
            }

            if (!preg_match('/^p(\d{6})$/', $partitionName, $matches)) {
                continue;
            }

            if ($matches[1] < $cutoffMonth) {
                $expiredPartitions[] = $partitionName;
            }
        }

        // 刪除超過三個月前的所有月分區
        if (!empty($expiredPartitions)) {
            $partitionList = implode(', ', $expiredPartitions);
            DB::statement("ALTER TABLE product_view_counts DROP PARTITION {$partitionList}");
        }

        return self::SUCCESS;
    }
}
