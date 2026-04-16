<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Cache;

class ProductRepository extends Repository
{
    /**
     * 取得所有產品
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getAllProducts()
    {
        // 取得所有產品編號
        $productIds = Cache::remember('all_product_ids', 3600, function () {
            return $this->modelClassName::pluck('id')->all();
        });

        // 將產品編號組合成 Cache key
        $cacheKeys = array_map(fn ($id) => "product:$id", $productIds);

        // 取得存在 Cache 中的產品資料
        $products = Cache::many($cacheKeys);

        // 取得不存在於 Cache 中的產品編號
        $missingProductIds = array_map(fn ($key) => (int) str_replace('product:', '', $key), array_filter($cacheKeys, fn ($key) => !isset($products[$key])));

        // 如果有缺少的產品資料，從資料庫中取得並存入 Cache
        foreach ($missingProductIds as $productId) {
            $product = $this->modelClassName::find($productId);

            if (!empty($product)) {
                $product->load('images');
                $product = [
                    'id' => $product->id,
                    'product_spec_id' => $product->product_spec_id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'description' => $product->description,
                    'view_counts' => $product->view_counts,
                    'image_path' => $product->images->first()?->url ?? '/storage/images/products/default.png',
                ];
                $cacheKey = "product:$productId";
                Cache::tags(['products'])->put($cacheKey, $product, 3600);
                $products[$cacheKey] = $product;
            }
        }

        return array_values($products);
    }
}
