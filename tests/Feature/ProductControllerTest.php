<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\ProductVariant;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    /**
     * 商品路由: 非數字 ID 應回傳 404，不得轉型後查詢或修改有效商品。
     */
    public function test_product_routes_reject_non_numeric_identifiers(): void
    {
        $product = Product::factory()->create();
        $before = $product->refresh()->getAttributes();
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getProductData')->never();
        $this->app->instance(ProductService::class, $productService);

        foreach (['not-a-product', "-{$product->id}", "{$product->id}.5"] as $identifier) {
            foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
                $this->json($method, "/api/products/{$identifier}")->assertNotFound();
            }
        }

        $this->assertSame($before, $product->refresh()->getAttributes());
    }

    /**
     * 產品列表：資料格式錯誤時應回傳 422。
     */
    public function test_index_returns_422_when_validation_fails(): void
    {
        $response = $this->getJson('/api/products?row_counts_per_page=31&page=0');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['row_counts_per_page', 'page']);
    }

    /**
     * 產品列表：驗證失敗時不應呼叫 service。
     */
    public function test_index_does_not_call_service_when_validation_fails(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getPopularProducts')->never();

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products?row_counts_per_page=abc&page=0');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['row_counts_per_page', 'page']);
    }

    /**
     * 產品列表：未帶參數時應使用預設分頁參數並回傳資源集合。
     */
    public function test_index_uses_default_parameters_and_returns_resource_collection(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getPopularProducts')
            ->once()
            ->with(10, 1)
            ->andReturn([
                'products' => [
                    [
                        'id' => 11,
                        'name' => '產品A',
                        'description' => '產品A描述',
                        'view_counts' => 55,
                        'image_path' => '/storage/images/products/a.png',
                        'variants' => [],
                    ],
                ],
                'total_row_counts' => 1,
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonPath('data.0.id', 11)
            ->assertJsonPath('data.0.name', '產品A')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * 產品列表：帶入自訂分頁參數時應正確呼叫 service 並回傳分頁資訊。
     */
    public function test_index_passes_custom_parameters_to_service_and_returns_pagination_meta(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getPopularProducts')
            ->once()
            ->with(3, 2)
            ->andReturn([
                'products' => [
                    [
                        'id' => 21,
                        'name' => '產品B',
                        'description' => '產品B描述',
                        'view_counts' => 88,
                        'image_path' => '/storage/images/products/b.png',
                        'variants' => [],
                    ],
                    [
                        'id' => 22,
                        'name' => '產品C',
                        'description' => '產品C描述',
                        'view_counts' => 66,
                        'image_path' => '/storage/images/products/c.png',
                        'variants' => [],
                    ],
                ],
                'total_row_counts' => 8,
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products?row_counts_per_page=3&page=2');

        $response->assertOk()
            ->assertJsonPath('data.0.id', 21)
            ->assertJsonPath('data.1.id', 22)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.total', 8);
    }

    /**
     * 產品列表：row_counts_per_page 允許最小邊界值 1。
     */
    public function test_index_accepts_minimum_row_count_boundary(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getPopularProducts')
            ->once()
            ->with(1, 1)
            ->andReturn([
                'products' => [],
                'total_row_counts' => 0,
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products?row_counts_per_page=1');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * 產品列表：row_counts_per_page 允許最大邊界值 30。
     */
    public function test_index_accepts_maximum_row_count_boundary(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getPopularProducts')
            ->once()
            ->with(30, 1)
            ->andReturn([
                'products' => [],
                'total_row_counts' => 0,
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products?row_counts_per_page=30');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 30)
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * 產品列表：僅帶 row_counts_per_page 時，page 應使用預設值。
     */
    public function test_index_uses_default_page_when_only_row_count_is_provided(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getPopularProducts')
            ->once()
            ->with(5, 1)
            ->andReturn([
                'products' => [],
                'total_row_counts' => 0,
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products?row_counts_per_page=5');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * 產品列表：僅帶 page 時，row_counts_per_page 應使用預設值。
     */
    public function test_index_uses_default_row_count_when_only_page_is_provided(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getPopularProducts')
            ->once()
            ->with(10, 3)
            ->andReturn([
                'products' => [],
                'total_row_counts' => 0,
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products?page=3');

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * 產品列表：應回傳完整 ProductResource 欄位。
     */
    public function test_index_returns_full_product_resource_fields(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getPopularProducts')
            ->once()
            ->with(10, 1)
            ->andReturn([
                'products' => [
                    [
                        'id' => 31,
                        'name' => '產品E',
                        'description' => '產品E描述',
                        'view_counts' => 101,
                        'image_path' => '/storage/images/products/e.png',
                        'variants' => [[
                            'id' => 91,
                            'product_spec_id' => 9,
                            'sku' => 'PRODUCT-E-BLACK-M',
                            'price' => 599,
                            'stock_quantity' => 12,
                            'spec' => ['color' => '黑', 'size' => 3],
                        ]],
                    ],
                ],
                'total_row_counts' => 1,
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products');

        $response->assertOk()
            ->assertJsonPath('data.0.id', 31)
            ->assertJsonPath('data.0.name', '產品E')
            ->assertJsonPath('data.0.description', '產品E描述')
            ->assertJsonPath('data.0.view_counts', 101)
            ->assertJsonPath('data.0.image_path', '/storage/images/products/e.png')
            ->assertJsonPath('data.0.variants.0.product_spec_id', 9)
            ->assertJsonPath('data.0.variants.0.sku', 'PRODUCT-E-BLACK-M')
            ->assertJsonPath('data.0.variants.0.price', 599)
            ->assertJsonPath('data.0.variants.0.stock_quantity', 12);
    }

    /**
     * 單一產品：產品存在時應呼叫 service 並回傳資源內容。
     */
    public function test_show_returns_product_resource_when_product_exists(): void
    {
        $productSpec = ProductSpec::query()->create([
            'color' => '藍',
            'size' => 3,
        ]);

        $product = Product::query()->create([
            'name' => '產品D',
            'description' => '產品D描述',
            'view_counts' => 10,
        ]);
        $productVariant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'product_spec_id' => $productSpec->id,
            'price' => 499,
        ]);

        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getProductData')
            ->once()
            ->with(Mockery::on(fn (Product $boundProduct): bool => $boundProduct->id === $product->id))
            ->andReturn([
                'id' => $product->id,
                'name' => '產品D',
                'description' => '產品D描述',
                'view_counts' => 11,
                'image_path' => '/storage/images/products/d.png',
                'variants' => [[
                    'id' => $productVariant->id,
                    'product_spec_id' => $productSpec->id,
                    'sku' => $productVariant->sku,
                    'price' => 499,
                    'stock_quantity' => $productVariant->stock_quantity,
                    'spec' => ['color' => '藍', 'size' => 3],
                ]],
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products/'.$product->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', '產品D')
            ->assertJsonPath('data.view_counts', 11);
    }

    /**
     * 單一產品：產品不存在時應回傳 404，且不呼叫 service。
     */
    public function test_show_returns_404_when_product_not_found(): void
    {
        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getProductData')->never();

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products/999999');

        $response->assertStatus(404);
    }

    /**
     * 單一產品：應回傳完整 ProductResource 欄位。
     */
    public function test_show_returns_full_product_resource_fields(): void
    {
        $productSpec = ProductSpec::query()->create([
            'color' => '黑',
            'size' => 2,
        ]);

        $product = Product::query()->create([
            'name' => '產品F',
            'description' => '產品F描述',
            'view_counts' => 19,
        ]);
        $productVariant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'product_spec_id' => $productSpec->id,
            'sku' => 'PRODUCT-F-BLACK-S',
            'price' => 699,
            'stock_quantity' => 7,
        ]);

        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getProductData')
            ->once()
            ->with(Mockery::on(fn (Product $boundProduct): bool => $boundProduct->id === $product->id))
            ->andReturn([
                'id' => $product->id,
                'name' => '產品F',
                'description' => '產品F描述',
                'view_counts' => 20,
                'image_path' => '/storage/images/products/f.png',
                'variants' => [[
                    'id' => $productVariant->id,
                    'product_spec_id' => $productSpec->id,
                    'sku' => 'PRODUCT-F-BLACK-S',
                    'price' => 699,
                    'stock_quantity' => 7,
                    'spec' => ['color' => '黑', 'size' => 2],
                ]],
            ]);

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products/'.$product->id);

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', '產品F')
            ->assertJsonPath('data.description', '產品F描述')
            ->assertJsonPath('data.view_counts', 20)
            ->assertJsonPath('data.image_path', '/storage/images/products/f.png')
            ->assertJsonPath('data.variants.0.product_spec_id', $productSpec->id)
            ->assertJsonPath('data.variants.0.sku', 'PRODUCT-F-BLACK-S')
            ->assertJsonPath('data.variants.0.price', 699)
            ->assertJsonPath('data.variants.0.stock_quantity', 7);
    }

    /**
     * 單一產品：當 service 發生未預期錯誤時應回傳 500。
     */
    public function test_show_returns_500_when_service_throws_unexpected_exception(): void
    {
        $productSpec = ProductSpec::query()->create([
            'color' => '白',
            'size' => 1,
        ]);

        $product = Product::query()->create([
            'name' => '產品G',
            'description' => '產品G描述',
            'view_counts' => 3,
        ]);

        $productService = Mockery::mock(ProductService::class);
        $productService->shouldReceive('getProductData')
            ->once()
            ->andThrow(new \RuntimeException('unexpected error'));

        $this->app->instance(ProductService::class, $productService);

        $response = $this->getJson('/api/products/'.$product->id);

        $response->assertStatus(500);
    }

    /**
     * 建立產品：目前尚未實作，應回傳空內容 200。
     */
    public function test_store_returns_empty_200_response_for_not_implemented_endpoint(): void
    {
        $response = $this->postJson('/api/products', []);

        $response->assertOk();
        $this->assertSame('', $response->getContent());
    }

    /**
     * 更新產品：目前尚未實作，應回傳空內容 200。
     */
    public function test_update_returns_empty_200_response_for_not_implemented_endpoint(): void
    {
        $response = $this->putJson('/api/products/1', []);

        $response->assertOk();
        $this->assertSame('', $response->getContent());
    }

    /**
     * 刪除產品：目前尚未實作，應回傳空內容 200。
     */
    public function test_destroy_returns_empty_200_response_for_not_implemented_endpoint(): void
    {
        $response = $this->deleteJson('/api/products/1');

        $response->assertOk();
        $this->assertSame('', $response->getContent());
    }
}
