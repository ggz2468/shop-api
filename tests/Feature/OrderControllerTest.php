<?php

namespace Tests\Feature;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\ShippingMethod;
use App\Enums\Order\Status;
use App\Enums\Order\StoreType;
use App\Enums\PaymentTransaction\PaymentMethod as PaymentTransactionPaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status as PaymentTransactionStatus;
use App\Events\OrderCreated;
use App\Models\Member;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\ProductVariant;
use App\Notifications\OrderCreatedNotification;
use App\Services\OrderService;
use App\Stores\CartStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建立訂單: 未驗證的訪客無法建立訂單。
     */
    public function test_guest_cannot_create_order(): void
    {
        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 建立訂單: 建立訂單時必須提供 Idempotency-Key header。
     */
    public function test_store_returns_422_when_idempotency_key_header_is_missing(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    /**
     * 建立訂單: Idempotency-Key header 不可超過 64 個字元。
     */
    public function test_store_returns_422_when_idempotency_key_header_is_too_long(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
        ], [
            'Idempotency-Key' => str_repeat('a', 65),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    /**
     * 建立訂單: 建立訂單時必須提供付款方式。
     */
    public function test_store_returns_422_when_payment_method_is_missing(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    /**
     * 建立訂單: 付款方式必須是系統支援的付款方式。
     */
    public function test_store_returns_422_when_payment_method_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        foreach ([0, 99, 'abc'] as $paymentMethod) {
            $response = $this->postJson('/api/orders', [
                'payment_method' => $paymentMethod,
                'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            ], [
                'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['payment_method']);
        }
    }

    /**
     * 建立訂單: 建立訂單時必須提供配送方式。
     */
    public function test_store_returns_422_when_shipping_method_is_missing(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shipping_method']);
    }

    /**
     * 建立訂單: 配送方式必須是系統支援的配送方式。
     */
    public function test_store_returns_422_when_shipping_method_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        foreach ([0, 99, 'abc'] as $shippingMethod) {
            $response = $this->postJson('/api/orders', [
                'payment_method' => PaymentMethod::CREDIT_CARD->value,
                'shipping_method' => $shippingMethod,
            ], [
                'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['shipping_method']);
        }
    }

    /**
     * 建立訂單: 超商取貨必須提供門市資訊。
     */
    public function test_store_returns_422_when_store_information_is_missing_for_convenience_store_shipping(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['store_type', 'store_code', 'store_name', 'store_address']);
    }

    /**
     * 建立訂單: 超商類型必須是系統支援的超商通路。
     */
    public function test_store_returns_422_when_store_type_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'store_type' => 'UNKNOWN',
            'store_code' => 'STORE001',
            'store_name' => '測試門市',
            'store_address' => '台北市信義區測試路 1 號',
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['store_type']);
    }

    /**
     * 建立訂單: 已驗證的會員可以從購物車建立訂單。
     */
    public function test_store_creates_order_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $idempotencyKey = '01J3QS2AJMZV09DNXQ2EE4NM2E';

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')
            ->once()
            ->with($member->id, $idempotencyKey, PaymentMethod::CREDIT_CARD->value, ShippingMethod::HOME_DELIVERY->value, null, null, null, null)
            ->andReturn([
                'status' => 201,
                'message' => '訂單已建立。',
                'data' => [
                    'id' => 1,
                    'number' => 'ORD20260823K7P4XQ',
                    'status' => 3,
                    'payment_method' => PaymentMethod::CREDIT_CARD->value,
                    'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                    'store_type' => null,
                    'store_code' => null,
                    'store_name' => null,
                    'store_address' => null,
                    'total_amount' => 1200,
                    'tax_amount' => 60,
                    'shipping_fee' => 80,
                    'items' => [
                        [
                            'product_variant_id' => 11,
                            'product_name' => '產品A',
                            'product_price' => 1060,
                            'quantity' => 1,
                            'subtotal' => 1060,
                        ],
                    ],
                ],
            ]);

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', '訂單已建立。')
            ->assertJsonPath('data.number', 'ORD20260823K7P4XQ')
            ->assertJsonPath('data.payment_method', PaymentMethod::CREDIT_CARD->value)
            ->assertJsonPath('data.items.0.product_variant_id', 11);
    }

    /**
     * 建立訂單: 重複使用相同 Idempotency-Key 時應回傳既有訂單。
     */
    public function test_store_returns_existing_order_when_idempotency_key_was_already_used(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $idempotencyKey = '01J3QS2AJMZV09DNXQ2EE4NM2E';

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')
            ->once()
            ->with($member->id, $idempotencyKey, PaymentMethod::CREDIT_CARD->value, ShippingMethod::HOME_DELIVERY->value, null, null, null, null)
            ->andReturn([
                'status' => 200,
                'message' => '訂單已存在。',
                'data' => [
                    'id' => 1,
                    'number' => 'ORD20260823K7P4XQ',
                    'status' => 3,
                    'payment_method' => PaymentMethod::CREDIT_CARD->value,
                    'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                    'store_type' => null,
                    'store_code' => null,
                    'store_name' => null,
                    'store_address' => null,
                    'total_amount' => 1200,
                    'tax_amount' => 60,
                    'shipping_fee' => 80,
                    'items' => [],
                ],
            ]);

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', '訂單已存在。')
            ->assertJsonPath('data.number', 'ORD20260823K7P4XQ');
    }

    /**
     * 建立訂單: 真實結帳流程會建立訂單、明細、扣庫存、清空購物車並 dispatch OrderCreated。
     */
    public function test_store_persists_order_details_decrements_stock_clears_cart_and_dispatches_order_created(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = Product::factory()->create(['name' => 'Cotton Shirt']);
        $productSpec = ProductSpec::factory()->create(['color' => '黑', 'size' => 3]);
        $productVariant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'product_spec_id' => $productSpec->id,
            'sku' => 'TSHIRT-BLACK-M',
            'price' => 800,
            'stock_quantity' => 5,
        ]);
        $idempotencyKey = '01J3QS2AJMZV09DNXQ2EE4NM2E';

        app(CartStore::class)->storeItem($member->id, $productVariant->id, 2);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'store_type' => StoreType::UNIMART->value,
            'store_code' => 'UNIMART001',
            'store_name' => '信義門市',
            'store_address' => '台北市信義區測試路 1 號',
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', '訂單已建立。')
            ->assertJsonPath('data.total_amount', 1600)
            ->assertJsonPath('data.tax_amount', 77)
            ->assertJsonPath('data.shipping_fee', 0)
            ->assertJsonPath('data.status', Status::STOCKING->value)
            ->assertJsonPath('data.payment_method', PaymentMethod::CREDIT_CARD->value)
            ->assertJsonPath('data.shipping_method', ShippingMethod::CONVENIENCE_STORE->value)
            ->assertJsonPath('data.store_type', StoreType::UNIMART->value)
            ->assertJsonPath('data.store_code', 'UNIMART001')
            ->assertJsonPath('data.store_name', '信義門市')
            ->assertJsonPath('data.store_address', '台北市信義區測試路 1 號')
            ->assertJsonPath('data.payment_status', PaymentStatus::UNPAID->value)
            ->assertJsonPath('data.items.0.product_variant_id', $productVariant->id)
            ->assertJsonPath('data.items.0.product_name', 'Cotton Shirt')
            ->assertJsonPath('data.items.0.product_sku', 'TSHIRT-BLACK-M')
            ->assertJsonPath('data.items.0.product_color', '黑')
            ->assertJsonPath('data.items.0.product_size', 3)
            ->assertJsonPath('data.items.0.product_price', 800)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.subtotal', 1600);

        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'member_id' => $member->id,
            'idempotency_key' => $idempotencyKey,
            'total_amount' => 1600,
            'tax_amount' => 77,
            'shipping_fee' => 0,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'store_type' => StoreType::UNIMART->value,
            'store_code' => 'UNIMART001',
            'store_name' => '信義門市',
            'store_address' => '台北市信義區測試路 1 號',
            'status' => Status::STOCKING->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        $this->assertDatabaseHas('order_details', [
            'order_id' => $orderId,
            'product_variant_id' => $productVariant->id,
            'product_name' => 'Cotton Shirt',
            'product_sku' => 'TSHIRT-BLACK-M',
            'product_color' => '黑',
            'product_size' => 3,
            'product_price' => 800,
            'quantity' => 2,
            'subtotal' => 1600,
        ]);

        $this->assertSame(3, $productVariant->refresh()->stock_quantity);
        $this->assertSame([], app(CartStore::class)->getItems($member->id));
        Event::assertDispatched(OrderCreated::class, fn (OrderCreated $event): bool => $event->orderId === $orderId);
    }

    /**
     * 建立訂單: 真實結帳流程透過事件建立金流交易與付款頁 payload。
     */
    public function test_store_builds_payment_checkout_payload_through_events(): void
    {
        Notification::fake();

        config()->set('services.ecpay.merchant_id', '3002599');
        config()->set('services.ecpay.hash_key', 'spPjZn66i0OhqJsQ');
        config()->set('services.ecpay.hash_iv', 'hT5OJckN45isQTTs');
        config()->set('services.ecpay.payment_action_url', 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5');
        config()->set('services.ecpay.return_url', 'http://localhost/api/payment-callbacks/ecpay');
        config()->set('services.ecpay.client_back_url', 'http://localhost/orders');

        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $product = Product::factory()->create(['name' => 'Canvas Bag']);
        $productSpec = ProductSpec::factory()->create(['color' => '白', 'size' => 2]);
        $productVariant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'product_spec_id' => $productSpec->id,
            'sku' => 'BAG-WHITE-S',
            'price' => 600,
            'stock_quantity' => 4,
        ]);

        app(CartStore::class)->storeItem($member->id, $productVariant->id, 1);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2F',
        ]);

        $response->assertCreated();

        $order = Order::query()->where('id', $response->json('data.id'))->firstOrFail();
        $paymentTransaction = PaymentTransaction::query()->where('order_id', $order->id)->firstOrFail();

        $this->assertSame(Provider::ECPAY->value, $paymentTransaction->provider);
        $this->assertSame(PaymentTransactionStatus::PENDING->value, $paymentTransaction->status);
        $this->assertSame($order->total_amount, $paymentTransaction->amount);
        $this->assertSame(PaymentTransactionPaymentMethod::CREDIT_CARD->value, $paymentTransaction->payment_method);
        $this->assertSame('POST', $paymentTransaction->checkout_payload['method']);
        $this->assertSame('https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5', $paymentTransaction->checkout_payload['action']);
        $this->assertSame('3002599', $paymentTransaction->request_payload['MerchantID']);
        $this->assertSame($paymentTransaction->merchant_trade_no, $paymentTransaction->request_payload['MerchantTradeNo']);
        $this->assertSame('Credit', $paymentTransaction->request_payload['ChoosePayment']);
        $this->assertArrayHasKey('CheckMacValue', $paymentTransaction->request_payload);
        $this->assertNull($paymentTransaction->response_payload);
        Notification::assertSentTo($member, OrderCreatedNotification::class);
    }

    /**
     * 建立訂單: 非信用卡付款方式應建立對應 provider 的金流交易。
     */
    public function test_store_creates_payment_transaction_with_matching_provider_for_non_credit_card_payment_methods(): void
    {
        Notification::fake();

        $cases = [
            [PaymentMethod::ATM, PaymentTransactionPaymentMethod::ATM, Provider::ECPAY, '01J3QS2AJMZV09DNXQ2EE4NM2N'],
            [PaymentMethod::CVS, PaymentTransactionPaymentMethod::CVS, Provider::ECPAY, '01J3QS2AJMZV09DNXQ2EE4NM2P'],
            [PaymentMethod::BARCODE, PaymentTransactionPaymentMethod::BARCODE, Provider::ECPAY, '01J3QS2AJMZV09DNXQ2EE4NM2Q'],
        ];

        foreach ($cases as [$paymentMethod, $paymentTransactionPaymentMethod, $provider, $idempotencyKey]) {
            $member = Member::factory()->create();
            Sanctum::actingAs($member);
            $productVariant = ProductVariant::factory()->create([
                'price' => 500,
                'stock_quantity' => 3,
            ]);

            app(CartStore::class)->storeItem($member->id, $productVariant->id, 1);

            $response = $this->postJson('/api/orders', [
                'payment_method' => $paymentMethod->value,
                'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            ], [
                'Idempotency-Key' => $idempotencyKey,
            ]);

            $response->assertCreated()
                ->assertJsonPath('data.payment_method', $paymentMethod->value);

            $this->assertDatabaseHas('payment_transactions', [
                'order_id' => $response->json('data.id'),
                'provider' => $provider->value,
                'payment_method' => $paymentTransactionPaymentMethod->value,
                'status' => PaymentTransactionStatus::PENDING->value,
            ]);
        }
    }

    /**
     * 建立訂單: 重複 Idempotency-Key 不會重複扣庫存或重複 dispatch OrderCreated。
     */
    public function test_store_real_checkout_is_idempotent_for_same_member_and_key(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $productVariant = ProductVariant::factory()->create([
            'price' => 500,
            'stock_quantity' => 5,
        ]);
        $idempotencyKey = '01J3QS2AJMZV09DNXQ2EE4NM2G';

        app(CartStore::class)->storeItem($member->id, $productVariant->id, 2);

        $firstResponse = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $secondResponse = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $firstResponse->assertCreated();
        $secondResponse->assertOk()
            ->assertJsonPath('message', '訂單已存在。')
            ->assertJsonPath('data.id', $firstResponse->json('data.id'));

        $this->assertSame(1, Order::query()->where('member_id', $member->id)->where('idempotency_key', $idempotencyKey)->count());
        $this->assertSame(3, $productVariant->refresh()->stock_quantity);
        Event::assertDispatchedTimes(OrderCreated::class, 1);
    }

    /**
     * 建立訂單: 購物車包含不存在的產品規格時應回傳 409 且不建立訂單。
     */
    public function test_store_real_checkout_returns_409_when_cart_contains_missing_product_variant(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        app(CartStore::class)->storeItem($member->id, 999999, 1);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2H',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', '購物車包含不存在的產品。');
        $this->assertSame(0, Order::query()->where('member_id', $member->id)->count());
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 建立訂單: 真實結帳流程遇到庫存不足時不會建立訂單或扣庫存。
     */
    public function test_store_real_checkout_returns_409_when_stock_is_insufficient(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $productVariant = ProductVariant::factory()->create([
            'stock_quantity' => 1,
        ]);

        app(CartStore::class)->storeItem($member->id, $productVariant->id, 2);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2I',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('message', '產品庫存不足。');
        $this->assertSame(0, Order::query()->where('member_id', $member->id)->count());
        $this->assertSame(1, $productVariant->refresh()->stock_quantity);
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 建立訂單: 購物車為空時應回傳 409。
     */
    public function test_store_returns_409_when_cart_is_empty(): void
    {
        $this->assertStoreOrderServiceFailure(
            409,
            '購物車沒有可建立訂單的產品。',
        );
    }

    /**
     * 建立訂單: 庫存不足時應回傳 409。
     */
    public function test_store_returns_409_when_product_stock_is_insufficient(): void
    {
        $this->assertStoreOrderServiceFailure(
            409,
            '產品庫存不足。',
        );
    }

    /**
     * 建立訂單: 建立訂單發生暫時性錯誤時應回傳 503。
     */
    public function test_store_returns_503_when_order_service_cannot_create_order(): void
    {
        $this->assertStoreOrderServiceFailure(
            503,
            '訂單建立失敗，請稍後再試。',
        );
    }

    /**
     * 建立訂單: 建立訂單會套用 RateLimiter。
     */
    public function test_store_is_rate_limited_for_authenticated_member(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')
            ->times(10)
            ->with($member->id, Mockery::type('string'), PaymentMethod::CREDIT_CARD->value, ShippingMethod::HOME_DELIVERY->value, null, null, null, null)
            ->andReturn([
                'status' => 201,
                'message' => '訂單已建立。',
            ]);

        $this->app->instance(OrderService::class, $orderService);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/orders', [
                'payment_method' => PaymentMethod::CREDIT_CARD->value,
                'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            ], [
                'Idempotency-Key' => sprintf('01J3QS2AJMZV09DNXQ2EE4NM%02d', $attempt),
            ])->assertStatus(201);
        }

        $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM99',
        ])->assertStatus(429);
    }

    private function assertStoreOrderServiceFailure(int $status, string $message): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $idempotencyKey = '01J3QS2AJMZV09DNXQ2EE4NM2E';

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')
            ->once()
            ->with($member->id, $idempotencyKey, PaymentMethod::CREDIT_CARD->value, ShippingMethod::HOME_DELIVERY->value, null, null, null, null)
            ->andReturn([
                'status' => $status,
                'message' => $message,
            ]);

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertStatus($status)
            ->assertJson([
                'message' => $message,
            ])
            ->assertJsonMissingPath('data');
    }
}
