<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ProductService;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Repositories\ProductRepository;

class ProductController extends Controller
{
    /**
     * 建構子
     * 
     * @param \App\Services\ProductService $productService
     * @return void
     */
    public function __construct(
        private ProductService $productService
    ) {
        
    }

    /**
     * 取得首頁所需的熱門商品列表
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        // 資料格式驗證
        $validated = $request->validate([
            'row_counts_per_page' => 'integer|min:6|max:30|nullable',
            'page' => 'integer|min:1|nullable',
        ]);

        $parameters = [
            $validated['row_counts_per_page'] ?? ProductRepository::DEFAULT_ROW_COUNTS_PER_PAGE,
            $validated['page'] ?? ProductRepository::DEFAULT_PAGE,
        ];
        $products = $this->productService->getPopularProducts(...$parameters);
        return ProductResource::collection($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * 取得單一產品資料
     * 
     * @param \App\Models\Product $product
     * @return \App\Http\Resources\ProductResource
     */
    public function show(Product $product)
    {
        $productData = $this->productService->getProductData($product);
        return new ProductResource($productData);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
