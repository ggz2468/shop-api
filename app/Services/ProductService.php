<?php

namespace App\Services;

use App\Repositories\ProductRepository;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class ProductService
{
    /**
     * 預設取得資料筆數
     * 
     * @var int
     */
    public const int DEFAULT_ROW_COUNT = 10;

    /**
     * 建構子
     * 
     * @param \App\Repositories\ProductRepository $productRepository
     * @return void
     */
    public function __construct(
        private ProductRepository $productRepository
    ) {
        
    }

    /**
     * 取得熱門產品: 熱門產品為依據被瀏覽次數由多至少前十名的產品
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getPopularProducts()
    {
        return $this->productRepository->getProducts(self::DEFAULT_ROW_COUNT);
    }

    /**
     * 取得單一產品資料
     * 
     * @param \App\Models\Product $product
     * @return array<string, mixed>
     */
    public function getProductData(Product $product)
    {
        $cacheKey = "product:{$product->id}";

        // 將儲存於 Redis 中的產品被瀏覽次數遞增
        Redis::zIncrby('product_view_counts', 1, (string) $product->id);

        // 如果產品資料存在於 Cache 中，直接從 Cache 中取得並回傳
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $product->load('images');
        $productData = [
            'id' => $product->id,
            'product_spec_id' => $product->product_spec_id,
            'name' => $product->name,
            'price' => $product->price,
            'description' => $product->description,
            'view_counts' => $product->view_counts,
            'image_path' => $product->images->first()?->url ?? '/storage/images/products/default.png'
        ];

        // 將產品資料存入 Cache 中，並設定過期時間為 1 小時
        Cache::tags(['products'])->put($cacheKey, $productData, 3600);

        return $productData;
    }
}
