<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ProductService;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Repositories\ProductRepository;
use Illuminate\Pagination\LengthAwarePaginator;

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
     * 取得熱門產品列表
     * 
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        // 資料格式驗證
        $validated = $request->validate([
            'row_counts_per_page' => 'integer|min:1|max:30|nullable',
            'page' => 'integer|min:1|nullable',
        ]);

        $parameters = [
            $validated['row_counts_per_page'] ?? ProductRepository::DEFAULT_ROW_COUNTS_PER_PAGE,
            $validated['page'] ?? ProductRepository::DEFAULT_PAGE,
        ];
        $data = $this->productService->getPopularProducts(...$parameters);

        // 定義包含頁碼資訊的分頁物件
        $paginator = new LengthAwarePaginator($data['products'], $data['total_row_counts'], $parameters[0], $parameters[1]);

        return ProductResource::collection($paginator);
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
