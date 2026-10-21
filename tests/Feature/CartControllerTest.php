<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\ProductVariant;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class CartControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 購物車路由: 非數字規格 ID 在驗證身分前回傳 404，且不得觸發購物車修改。
     */
    public function test_cart_item_routes_reject_non_numeric_identifiers(): void
    {
        $member = Member::factory()->create();
        $variant = ProductVariant::factory()->create();
        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('updateCartItem')->never();
        $cartService->shouldReceive('destroyCartItem')->never();
        $this->app->instance(CartService::class, $cartService);

        $this->patchJson('/api/cart/items/not-a-variant', ['quantity' => 3])->assertNotFound();
        $this->deleteJson('/api/cart/items/not-a-variant')->assertNotFound();

        Sanctum::actingAs($member);

        foreach (['not-a-variant', "-{$variant->id}", "{$variant->id}.5"] as $identifier) {
            $this->patchJson("/api/cart/items/{$identifier}", ['quantity' => 3])->assertNotFound();
            $this->deleteJson("/api/cart/items/{$identifier}")->assertNotFound();
        }
    }

    /**
     * 顯示購物車內容: 未驗證的訪客無法存取購物車相關的 API 端點
     */
    public function test_guest_cannot_access_cart(): void
    {
        $response = $this->getJson('/api/cart');

        $response->assertStatus(401);
    }

    /**
     * 顯示購物車內容: 已驗證的會員可以存取購物車相關的 API 端點
     */
    public function test_show_returns_empty_cart_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $this->mockCartItemsForMember($member, []);

        $response = $this->getJson('/api/cart');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items',
                    'total_quantity',
                ],
            ])
            ->assertJson([
                'data' => [
                    'items' => [],
                    'total_quantity' => 0,
                ],
            ]);
    }

    /**
     * 顯示購物車內容: 已驗證的會員可以存取購物車相關的 API 端點，並返回購物車中的產品
     */
    public function test_show_returns_cart_items_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        // 模擬購物車中有產品
        $cartItems = [
            [
                'product_variant_id' => 1,
                'quantity' => 2,
                'product_name' => '經典白襯衫',
                'product_image_path' => '/images/products/shirt.svg',
            ],
            [
                'product_variant_id' => 2,
                'quantity' => 1,
                'product_name' => '直筒牛仔褲',
                'product_image_path' => '/images/products/jeans.svg',
            ],
        ];

        $this->mockCartItemsForMember($member, $cartItems);

        $response = $this->getJson('/api/cart');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => [
                            'product_variant_id',
                            'quantity',
                            'product_name',
                            'product_image_path',
                        ],
                    ],
                    'total_quantity',
                ],
            ])
            ->assertJson([
                'data' => [
                    'items' => $cartItems,
                    'total_quantity' => 3,
                ],
            ]);
    }

    /**
     * 顯示購物車內容: 查詢購物車時會使用目前登入會員的 ID
     */
    public function test_show_uses_authenticated_member_id_to_fetch_cart_items(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $this->mockCartItemsForMember($member, []);

        $response = $this->getJson('/api/cart');

        $response->assertOk();
    }

    /**
     * 顯示購物車內容: 購物車產品項目回傳指定欄位結構
     */
    public function test_show_returns_expected_cart_item_structure(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $this->mockCartItemsForMember($member, [
            [
                'product_variant_id' => 1,
                'quantity' => 2,
                'product_name' => '經典白襯衫',
                'product_image_path' => '/images/products/shirt.svg',
            ],
        ]);

        $response = $this->getJson('/api/cart');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => [
                            'product_variant_id',
                            'quantity',
                            'product_name',
                            'product_image_path',
                        ],
                    ],
                    'total_quantity',
                ],
            ]);
    }

    /**
     * 顯示購物車內容: 購物車總數量會加總所有產品項目的數量
     */
    public function test_show_calculates_total_quantity_from_cart_items(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $this->mockCartItemsForMember($member, [
            [
                'product_variant_id' => 1,
                'quantity' => 2,
                'product_name' => '經典白襯衫',
                'product_image_path' => '/images/products/shirt.svg',
            ],
            [
                'product_variant_id' => 2,
                'quantity' => 5,
                'product_name' => '直筒牛仔褲',
                'product_image_path' => '/images/products/jeans.svg',
            ],
            [
                'product_variant_id' => 3,
                'quantity' => 1,
                'product_name' => '黑色皮帶',
                'product_image_path' => '/images/products/belt.svg',
            ],
        ]);

        $response = $this->getJson('/api/cart');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'total_quantity' => 8,
                ],
            ]);
    }

    /**
     * 顯示購物車內容: 查詢購物車時會套用 RateLimiter。
     */
    public function test_show_is_rate_limited_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('getCartItems')
            ->times(60)
            ->with($member->id)
            ->andReturn([]);

        $this->app->instance(CartService::class, $cartService);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->getJson('/api/cart')->assertOk();
        }

        $this->getJson('/api/cart')->assertStatus(429);
    }

    /**
     * 將產品加入購物車: 未驗證的訪客無法加入產品到購物車。
     */
    public function test_guest_cannot_add_product_to_cart(): void
    {
        $response = $this->postJson('/api/cart/items', [
            'product_variant_id' => 1,
            'quantity' => 1,
        ]);

        $response->assertStatus(401);
    }

    /**
     * 將產品加入購物車: 加入產品時必須提供產品 ID 與數量。
     */
    public function test_store_returns_422_when_required_payload_is_missing(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('storeCartItem')->never();

        $this->app->instance(CartService::class, $cartService);

        $response = $this->postJson('/api/cart/items', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_variant_id', 'quantity']);
    }

    /**
     * 將產品加入購物車: 加入產品時產品 ID 必須存在。
     */
    public function test_store_returns_422_when_product_does_not_exist(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('storeCartItem')->never();

        $this->app->instance(CartService::class, $cartService);

        $response = $this->postJson('/api/cart/items', [
            'product_variant_id' => 999,
            'quantity' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_variant_id']);
    }

    /**
     * 將產品加入購物車: 加入產品時產品 ID 必須是整數。
     */
    public function test_store_returns_422_when_product_id_is_not_integer(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('storeCartItem')->never();

        $this->app->instance(CartService::class, $cartService);

        $response = $this->postJson('/api/cart/items', [
            'product_variant_id' => 'abc',
            'quantity' => 1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_variant_id']);
    }

    /**
     * 將產品加入購物車: 加入產品時數量必須是正整數。
     */
    public function test_store_returns_422_when_quantity_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('storeCartItem')->never();

        $this->app->instance(CartService::class, $cartService);

        foreach ([0, -1, 'abc'] as $quantity) {
            $response = $this->postJson('/api/cart/items', [
                'product_variant_id' => $product->id,
                'quantity' => $quantity,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['quantity']);
        }
    }

    /**
     * 將產品加入購物車: 已驗證的會員可以加入產品到購物車，並回傳 service 結果。
     */
    public function test_store_adds_product_to_cart_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('storeCartItem')
            ->once()
            ->with($member->id, $product->id, 2)
            ->andReturn([
                'status' => 201,
                'message' => '產品已加入購物車。',
                'data' => [
                    'product_variant_id' => $product->id,
                    'quantity' => 2,
                    'product_name' => $product->product->name,
                    'product_image_path' => '/images/products/default.svg',
                ],
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => '產品已加入購物車。',
                'data' => [
                    'product_variant_id' => $product->id,
                    'quantity' => 2,
                    'product_name' => $product->product->name,
                    'product_image_path' => '/images/products/default.svg',
                ],
            ]);
    }

    /**
     * 將產品加入購物車: 加入產品後可從購物車查詢 API 讀回該產品。
     */
    public function test_store_persists_product_to_cart_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(201)
            ->assertJson([
                'message' => '產品已加入購物車。',
                'data' => [
                    'product_variant_id' => $product->id,
                    'quantity' => 2,
                ],
            ]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_variant_id' => $product->id,
                            'quantity' => 2,
                            'product_name' => $product->product->name,
                            'product_image_path' => '/images/products/default.svg',
                        ],
                    ],
                    'total_quantity' => 2,
                ],
            ]);
    }

    /**
     * 將產品加入購物車: 不同會員的購物車資料會互相隔離。
     */
    public function test_store_keeps_cart_items_isolated_between_members(): void
    {
        $member = Member::factory()->create();
        $anotherMember = Member::factory()->create();
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");
        Cache::forget("cart:member:{$anotherMember->id}:items");

        Sanctum::actingAs($member);
        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(201);

        Sanctum::actingAs($anotherMember);
        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [],
                    'total_quantity' => 0,
                ],
            ]);
    }

    /**
     * 將產品加入購物車: 重複加入相同產品時會累加購物車中的產品數量。
     */
    public function test_store_increments_quantity_when_product_already_exists_in_cart(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(201);

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(201);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_variant_id' => $product->id,
                            'quantity' => 3,
                            'product_name' => $product->product->name,
                            'product_image_path' => '/images/products/default.svg',
                        ],
                    ],
                    'total_quantity' => 3,
                ],
            ]);
    }

    /**
     * 將產品加入購物車: 加入產品時 service 回傳失敗狀態應原樣回傳。
     */
    public function test_store_propagates_service_failure_status_and_message(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('storeCartItem')
            ->once()
            ->with($member->id, $product->id, 3)
            ->andReturn([
                'status' => 409,
                'message' => '產品無法加入購物車。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 3,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'message' => '產品無法加入購物車。',
            ]);
    }

    /**
     * 將產品加入購物車: 加入產品時 service 儲存失敗應回傳 503 錯誤訊息。
     */
    public function test_store_returns_503_when_cart_service_cannot_store_item(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('storeCartItem')
            ->once()
            ->with($member->id, $product->id, 1)
            ->andReturn([
                'status' => 503,
                'message' => '產品加入會員購物車失敗，請稍後再試。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'message' => '產品加入會員購物車失敗，請稍後再試。',
                'data' => [],
            ]);
    }

    /**
     * 將產品加入購物車: 加入產品會套用 RateLimiter。
     */
    public function test_store_is_rate_limited_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('storeCartItem')
            ->times(60)
            ->with($member->id, $product->id, 1)
            ->andReturn([
                'status' => 201,
                'message' => '產品已加入購物車。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson('/api/cart/items', [
                'product_variant_id' => $product->id,
                'quantity' => 1,
            ])->assertStatus(201);
        }

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(429);
    }

    /**
     * 更新購物車產品數量: 未驗證的訪客無法更新購物車產品數量。
     */
    public function test_guest_cannot_update_cart_item_quantity(): void
    {
        $product = ProductVariant::factory()->create();

        $response = $this->patchJson("/api/cart/items/{$product->id}", [
            'quantity' => 2,
        ]);

        $response->assertStatus(401);
    }

    /**
     * 更新購物車產品數量: 更新數量時必須提供數量。
     */
    public function test_update_returns_422_when_required_payload_is_missing(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('updateCartItem')->never();

        $this->app->instance(CartService::class, $cartService);

        $response = $this->patchJson("/api/cart/items/{$product->id}", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    /**
     * 更新購物車產品數量: 更新數量時數量必須是正整數。
     */
    public function test_update_returns_422_when_quantity_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('updateCartItem')->never();

        $this->app->instance(CartService::class, $cartService);

        foreach ([0, -1, 'abc'] as $quantity) {
            $response = $this->patchJson("/api/cart/items/{$product->id}", [
                'quantity' => $quantity,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['quantity']);
        }
    }

    /**
     * 更新購物車產品數量: 更新數量時路由中的產品必須存在。
     */
    public function test_update_returns_404_when_product_does_not_exist(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('updateCartItem')->never();

        $this->app->instance(CartService::class, $cartService);

        $response = $this->patchJson('/api/cart/items/999', [
            'quantity' => 2,
        ]);

        $response->assertStatus(404);
    }

    /**
     * 更新購物車產品數量: 已驗證的會員可以更新購物車中的產品數量，並回傳 service 結果。
     */
    public function test_update_changes_cart_item_quantity_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('updateCartItem')
            ->once()
            ->with($member->id, $product->id, 3)
            ->andReturn([
                'status' => 200,
                'message' => '購物車產品數量已更新。',
                'data' => [
                    'product_variant_id' => $product->id,
                    'quantity' => 3,
                    'product_name' => $product->product->name,
                    'product_image_path' => '/images/products/default.svg',
                ],
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->patchJson("/api/cart/items/{$product->id}", [
            'quantity' => 3,
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => '購物車產品數量已更新。',
                'data' => [
                    'product_variant_id' => $product->id,
                    'quantity' => 3,
                    'product_name' => $product->product->name,
                    'product_image_path' => '/images/products/default.svg',
                ],
            ]);
    }

    /**
     * 更新購物車產品數量: 更新產品數量後可從購物車查詢 API 讀回更新後數量。
     */
    public function test_update_persists_cart_item_quantity_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(201);

        $this->patchJson("/api/cart/items/{$product->id}", [
            'quantity' => 4,
        ])->assertOk()
            ->assertJson([
                'message' => '購物車產品數量已更新。',
                'data' => [
                    'product_variant_id' => $product->id,
                    'quantity' => 4,
                ],
            ]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_variant_id' => $product->id,
                            'quantity' => 4,
                            'product_name' => $product->product->name,
                            'product_image_path' => '/images/products/default.svg',
                        ],
                    ],
                    'total_quantity' => 4,
                ],
            ]);
    }

    /**
     * 更新購物車產品數量: 產品存在但未加入會員購物車時回傳 404。
     */
    public function test_update_returns_404_when_existing_product_is_not_in_member_cart(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");

        $response = $this->patchJson("/api/cart/items/{$product->id}", [
            'quantity' => 2,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'message' => '會員購物車中找不到指定產品。',
                'data' => [],
            ]);
    }

    /**
     * 更新購物車產品數量: 更新不存在於購物車的產品時 service 回傳 404 應原樣回傳。
     */
    public function test_update_returns_404_when_product_is_not_in_cart(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('updateCartItem')
            ->once()
            ->with($member->id, $product->id, 2)
            ->andReturn([
                'status' => 404,
                'message' => '會員購物車中找不到指定產品。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->patchJson("/api/cart/items/{$product->id}", [
            'quantity' => 2,
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'message' => '會員購物車中找不到指定產品。',
                'data' => [],
            ]);
    }

    /**
     * 更新購物車產品數量: 更新產品數量時 service 儲存失敗應回傳 503 錯誤訊息。
     */
    public function test_update_returns_503_when_cart_service_cannot_update_item(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('updateCartItem')
            ->once()
            ->with($member->id, $product->id, 2)
            ->andReturn([
                'status' => 503,
                'message' => '會員購物車產品數量更新失敗，請稍後再試。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->patchJson("/api/cart/items/{$product->id}", [
            'quantity' => 2,
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'message' => '會員購物車產品數量更新失敗，請稍後再試。',
                'data' => [],
            ]);
    }

    /**
     * 更新購物車產品數量: 不同會員的購物車資料會互相隔離。
     */
    public function test_update_keeps_cart_items_isolated_between_members(): void
    {
        $member = Member::factory()->create();
        $anotherMember = Member::factory()->create();
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");
        Cache::forget("cart:member:{$anotherMember->id}:items");

        Sanctum::actingAs($member);
        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(201);

        Sanctum::actingAs($anotherMember);
        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 5,
        ])->assertStatus(201);

        Sanctum::actingAs($member);
        $this->patchJson("/api/cart/items/{$product->id}", [
            'quantity' => 3,
        ])->assertOk();

        Sanctum::actingAs($anotherMember);
        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_variant_id' => $product->id,
                            'quantity' => 5,
                            'product_name' => $product->product->name,
                            'product_image_path' => '/images/products/default.svg',
                        ],
                    ],
                    'total_quantity' => 5,
                ],
            ]);
    }

    /**
     * 更新購物車產品數量: 更新產品數量會套用 RateLimiter。
     */
    public function test_update_is_rate_limited_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('updateCartItem')
            ->times(60)
            ->with($member->id, $product->id, 1)
            ->andReturn([
                'status' => 200,
                'message' => '購物車產品數量已更新。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->patchJson("/api/cart/items/{$product->id}", [
                'quantity' => 1,
            ])->assertOk();
        }

        $this->patchJson("/api/cart/items/{$product->id}", [
            'quantity' => 1,
        ])->assertStatus(429);
    }

    /**
     * 刪除購物車中指定的產品: 未驗證的訪客無法刪除購物車產品。
     */
    public function test_guest_cannot_delete_cart_item(): void
    {
        $product = ProductVariant::factory()->create();

        $response = $this->deleteJson("/api/cart/items/{$product->id}");

        $response->assertStatus(401);
    }

    /**
     * 刪除購物車中指定的產品: 刪除產品時路由中的產品必須存在。
     */
    public function test_destroy_returns_404_when_product_does_not_exist(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('destroyCartItem')->never();

        $this->app->instance(CartService::class, $cartService);

        $response = $this->deleteJson('/api/cart/items/999');

        $response->assertStatus(404);
    }

    /**
     * 刪除購物車中指定的產品: 已驗證的會員可以刪除購物車中的產品，並回傳 service 結果。
     */
    public function test_destroy_removes_cart_item_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('destroyCartItem')
            ->once()
            ->with($member->id, $product->id)
            ->andReturn([
                'status' => 200,
                'message' => '購物車產品已刪除。',
                'data' => [
                    'product_variant_id' => $product->id,
                ],
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->deleteJson("/api/cart/items/{$product->id}");

        $response->assertOk()
            ->assertJson([
                'message' => '購物車產品已刪除。',
                'data' => [
                    'product_variant_id' => $product->id,
                ],
            ]);
    }

    /**
     * 刪除購物車中指定的產品: 刪除產品後無法再從購物車查詢 API 讀回該產品。
     */
    public function test_destroy_persists_removed_cart_item_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();
        $remainingProduct = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(201);

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $remainingProduct->id,
            'quantity' => 3,
        ])->assertStatus(201);

        $this->deleteJson("/api/cart/items/{$product->id}")
            ->assertOk()
            ->assertJson([
                'message' => '購物車產品已刪除。',
                'data' => [
                    'product_variant_id' => $product->id,
                ],
            ]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_variant_id' => $remainingProduct->id,
                            'quantity' => 3,
                            'product_name' => $remainingProduct->product->name,
                            'product_image_path' => '/images/products/default.svg',
                        ],
                    ],
                    'total_quantity' => 3,
                ],
            ])
            ->assertJsonMissing([
                'product_variant_id' => $product->id,
                'quantity' => 2,
            ]);
    }

    /**
     * 刪除購物車中指定的產品: 刪除購物車中唯一產品後會回到空購物車。
     */
    public function test_destroy_returns_empty_cart_after_removing_only_cart_item(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(201);

        $this->deleteJson("/api/cart/items/{$product->id}")
            ->assertOk()
            ->assertJson([
                'message' => '購物車產品已刪除。',
                'data' => [
                    'product_variant_id' => $product->id,
                ],
            ]);

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [],
                    'total_quantity' => 0,
                ],
            ]);
    }

    /**
     * 刪除購物車中指定的產品: 產品存在但未加入會員購物車時回傳 404。
     */
    public function test_destroy_returns_404_when_existing_product_is_not_in_member_cart(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");

        $response = $this->deleteJson("/api/cart/items/{$product->id}");

        $response->assertStatus(404)
            ->assertJson([
                'message' => '會員購物車中找不到指定產品。',
                'data' => [],
            ]);
    }

    /**
     * 刪除購物車中指定的產品: 刪除不存在於購物車的產品時 service 回傳 404 應原樣回傳。
     */
    public function test_destroy_returns_404_when_product_is_not_in_cart(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('destroyCartItem')
            ->once()
            ->with($member->id, $product->id)
            ->andReturn([
                'status' => 404,
                'message' => '會員購物車中找不到指定產品。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->deleteJson("/api/cart/items/{$product->id}");

        $response->assertStatus(404)
            ->assertJson([
                'message' => '會員購物車中找不到指定產品。',
                'data' => [],
            ]);
    }

    /**
     * 刪除購物車中指定的產品: 刪除產品時 service 儲存失敗應回傳 503 錯誤訊息。
     */
    public function test_destroy_returns_503_when_cart_service_cannot_destroy_item(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('destroyCartItem')
            ->once()
            ->with($member->id, $product->id)
            ->andReturn([
                'status' => 503,
                'message' => '會員購物車產品刪除失敗，請稍後再試。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->deleteJson("/api/cart/items/{$product->id}");

        $response->assertStatus(503)
            ->assertJson([
                'message' => '會員購物車產品刪除失敗，請稍後再試。',
                'data' => [],
            ]);
    }

    /**
     * 刪除購物車中指定的產品: 不同會員的購物車資料會互相隔離。
     */
    public function test_destroy_keeps_cart_items_isolated_between_members(): void
    {
        $member = Member::factory()->create();
        $anotherMember = Member::factory()->create();
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");
        Cache::forget("cart:member:{$anotherMember->id}:items");

        Sanctum::actingAs($member);
        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(201);

        Sanctum::actingAs($anotherMember);
        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 5,
        ])->assertStatus(201);

        Sanctum::actingAs($member);
        $this->deleteJson("/api/cart/items/{$product->id}")->assertOk();

        Sanctum::actingAs($anotherMember);
        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_variant_id' => $product->id,
                            'quantity' => 5,
                            'product_name' => $product->product->name,
                            'product_image_path' => '/images/products/default.svg',
                        ],
                    ],
                    'total_quantity' => 5,
                ],
            ]);
    }

    /**
     * 刪除購物車中指定的產品: 刪除產品會套用 RateLimiter。
     */
    public function test_destroy_is_rate_limited_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('destroyCartItem')
            ->times(60)
            ->with($member->id, $product->id)
            ->andReturn([
                'status' => 200,
                'message' => '購物車產品已刪除。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->deleteJson("/api/cart/items/{$product->id}")->assertOk();
        }

        $this->deleteJson("/api/cart/items/{$product->id}")->assertStatus(429);
    }

    /**
     * 清空購物車: 未驗證的訪客無法清空購物車。
     */
    public function test_guest_cannot_clear_cart_items(): void
    {
        $response = $this->deleteJson('/api/cart/items');

        $response->assertStatus(401);
    }

    /**
     * 清空購物車: 已驗證的會員可以清空購物車，並回傳 service 結果。
     */
    public function test_clear_removes_all_cart_items_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('clearCart')
            ->once()
            ->with($member->id)
            ->andReturn([
                'status' => 200,
                'message' => '購物車已清空。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->deleteJson('/api/cart/items');

        $response->assertOk()
            ->assertJson([
                'message' => '購物車已清空。',
            ])
            ->assertJsonMissingPath('data');
    }

    /**
     * 清空購物車: 清空後無法再從購物車查詢 API 讀回任何產品。
     */
    public function test_clear_persists_empty_cart_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = ProductVariant::factory()->create();
        $anotherProduct = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 2,
        ])->assertStatus(201);

        $this->postJson('/api/cart/items', [
            'product_variant_id' => $anotherProduct->id,
            'quantity' => 3,
        ])->assertStatus(201);

        $this->deleteJson('/api/cart/items')
            ->assertOk()
            ->assertJson([
                'message' => '購物車已清空。',
            ])
            ->assertJsonMissingPath('data');

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [],
                    'total_quantity' => 0,
                ],
            ]);
    }

    /**
     * 清空購物車: 清空空購物車也會回傳成功。
     */
    public function test_clear_returns_ok_when_cart_is_already_empty(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        Cache::forget("cart:member:{$member->id}:items");

        $response = $this->deleteJson('/api/cart/items');

        $response->assertOk()
            ->assertJson([
                'message' => '購物車已清空。',
            ])
            ->assertJsonMissingPath('data');
    }

    /**
     * 清空購物車: 清空購物車時 service 儲存失敗應回傳 503 錯誤訊息。
     */
    public function test_clear_returns_503_when_cart_service_cannot_clear_items(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('clearCart')
            ->once()
            ->with($member->id)
            ->andReturn([
                'status' => 503,
                'message' => '會員購物車清空失敗，請稍後再試。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        $response = $this->deleteJson('/api/cart/items');

        $response->assertStatus(503)
            ->assertJson([
                'message' => '會員購物車清空失敗，請稍後再試。',
            ])
            ->assertJsonMissingPath('data');
    }

    /**
     * 清空購物車: 不同會員的購物車資料會互相隔離。
     */
    public function test_clear_keeps_cart_items_isolated_between_members(): void
    {
        $member = Member::factory()->create();
        $anotherMember = Member::factory()->create();
        $product = ProductVariant::factory()->create();
        Cache::forget("cart:member:{$member->id}:items");
        Cache::forget("cart:member:{$anotherMember->id}:items");

        Sanctum::actingAs($member);
        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 1,
        ])->assertStatus(201);

        Sanctum::actingAs($anotherMember);
        $this->postJson('/api/cart/items', [
            'product_variant_id' => $product->id,
            'quantity' => 5,
        ])->assertStatus(201);

        Sanctum::actingAs($member);
        $this->deleteJson('/api/cart/items')->assertOk();

        Sanctum::actingAs($anotherMember);
        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'items' => [
                        [
                            'product_variant_id' => $product->id,
                            'quantity' => 5,
                            'product_name' => $product->product->name,
                            'product_image_path' => '/images/products/default.svg',
                        ],
                    ],
                    'total_quantity' => 5,
                ],
            ]);
    }

    /**
     * 清空購物車: 清空購物車會套用 RateLimiter。
     */
    public function test_clear_is_rate_limited_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('clearCart')
            ->times(60)
            ->with($member->id)
            ->andReturn([
                'status' => 200,
                'message' => '購物車已清空。',
            ]);

        $this->app->instance(CartService::class, $cartService);

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->deleteJson('/api/cart/items')->assertOk();
        }

        $this->deleteJson('/api/cart/items')->assertStatus(429);
    }

    /**
     * 模擬指定會員的購物車項目
     *
     * @param  array<int, array<string, mixed>>  $cartItems
     */
    private function mockCartItemsForMember(Member $member, array $cartItems): void
    {
        $cartService = Mockery::mock(CartService::class);
        $cartService->shouldReceive('getCartItems')
            ->once()
            ->with($member->id)
            ->andReturn($cartItems);

        $this->app->instance(CartService::class, $cartService);
    }
}
