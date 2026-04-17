<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class SyncProductViewCounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-product-view-counts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '將產品的被瀏覽次數從 Redis 同步到資料庫中';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 從 Redis 獲取產品的被瀏覽次數
        $productViewCounts = Redis::zrange('product_view_counts', 0, -1, ['withscores' => true]);
        $recordedAt = now()->startOfHour();

        DB::transaction(function () use ($productViewCounts, $recordedAt) {
            // 將被瀏覽次數同步到資料庫
            foreach ($productViewCounts as $productId => $viewCounts) {
                // 更新產品被瀏覽次數到資料庫
                Product::where('id', $productId)->increment('view_counts', (int) $viewCounts);

                // 更新產品被瀏覽次數到 product_view_counts 資料表中
                $updatedRows = DB::table('product_view_counts')
                    ->where('product_id', $productId)
                    ->where('recorded_at', $recordedAt)
                    ->update([
                        'view_counts' => DB::raw('view_counts + ' . (int) $viewCounts),
                    ]);

                // 若 product_view_counts 資料表中沒有相對應的資料，則直接新增一筆新的資料
                if ($updatedRows === 0) {
                    DB::table('product_view_counts')->insert([
                        'product_id' => $productId,
                        'recorded_at' => $recordedAt,
                        'view_counts' => (int) $viewCounts,
                    ]);
                }
            }
        });

        // 將所有產品資料的 Cache 清除，以確保下一次讀取時能從資料庫獲取最新的被瀏覽次數
        Cache::tags(['products'])->flush();

        // 清除 Redis 中的產品被瀏覽次數資料
        Redis::del('product_view_counts');
    }
}
