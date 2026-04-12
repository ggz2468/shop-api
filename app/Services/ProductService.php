<?php

namespace App\Services;

use App\Repositories\ProductRepository;

class ProductService
{
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
     * 依據建立時間由新到舊取得所有產品
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getAllProducts()
    {
        return $this->productRepository->getAllProducts();
    }
}
