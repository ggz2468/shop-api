<?php

namespace App\Repositories;

use App\Models\Product;

class ProductRepository extends Repository
{
    /**
     * 依據建立時間由新到舊取得所有產品
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getAllProducts()
    {
        $products = $this->get([], ['created_at', 'desc']);
        $products = $products->map(function (Product $product) {
            $product->load('images');
            return [
                'id' => $product->id,
                'product_spec_id' => $product->product_spec_id,
                'name' => $product->name,
                'price' => $product->price,
                'description' => $product->description,
                'image_path' => $product->images->first()?->url,
            ];
        })->all();
        return $products;
    }
}
