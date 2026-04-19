<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Cache;

class ProductRepository extends Repository
{
    /**
     * 預設排序欄位
     * 
     * @var string
     */
    public const string DEFAULT_SORT_FIELD = 'view_counts';

    /**
     * 預設排序方向
     * 
     * @var string
     */
    public const string DEFAULT_SORT_DIRECTION = 'desc';

    /**
     * 預設每頁資料筆數
     * 
     * @var int
     */
    public const int DEFAULT_ROW_COUNTS_PER_PAGE = 10;

    /**
     * 預設頁碼
     * 
     * @var int
     */
    public const int DEFAULT_PAGE = 1;

    /**
     * 取得產品
     * 
     * @param int $rowCountsPerPage 每頁資料筆數
     * @param int $page 頁碼
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(int $rowCountsPerPage = self::DEFAULT_ROW_COUNTS_PER_PAGE, int $page = self::DEFAULT_PAGE)
    {
        // 取得產品編號
        $productIdsCacheKey = "product_ids:page:{$page}:row_counts_per_page:{$rowCountsPerPage}";
        $productIds = Cache::tags(['products_index'])->remember($productIdsCacheKey, 3600, function () use ($rowCountsPerPage, $page) {
            return collect($this->paginate([], ['images'], [[self::DEFAULT_SORT_FIELD, self::DEFAULT_SORT_DIRECTION], ['id', 'asc']], $rowCountsPerPage, $page)->items())
                ->pluck('id')
                ->all();
        });

        // 將產品編號組合成 Cache key
        $cacheKeys = array_map(fn ($id) => "product:{$id}", $productIds);

        // 取得存在 Cache 中的產品資料
        $products = Cache::tags(['products'])->many($cacheKeys);

        // 取得不存在於 Cache 中的產品編號
        $missingProductIds = array_map(
            fn ($key) => (int) str_replace('product:', '', $key), array_filter($cacheKeys, fn ($key) => !isset($products[$key]))
        );

        // 取得不存在於 Cache 中的產品資料
        $missingProducts = $this->modelClassName::with('images')
            ->whereIn('id', $missingProductIds)
            ->get()
            ->mapWithKeys(fn ($product) => [
                "product:{$product->id}" => [
                    'id' => $product->id,
                    'product_spec_id' => $product->product_spec_id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'description' => $product->description,
                    'view_counts' => $product->view_counts,
                    'image_path' => $product->images->first()?->url ?? '/storage/images/products/default.png',
                ],
            ])
            ->all();

        // 將原先不存在於 Cache 中的產品資料存入 Cache
        Cache::tags(['products'])->putMany($missingProducts, 3600);

        // 將原先存在於 Cache 中的產品資料與剛剛從資料庫中取得的產品資料合併
        $products = array_merge($products, $missingProducts);

        // 再次將產品資料排序
        $products = collect($products)
            ->sortBy([
                [self::DEFAULT_SORT_FIELD, self::DEFAULT_SORT_DIRECTION],
                ['id', 'asc']
            ])
            ->values()
            ->all();

        return $products;
    }
}
