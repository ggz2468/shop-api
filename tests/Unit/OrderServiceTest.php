<?php

namespace Tests\Unit;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\ShippingMethod;
use App\Enums\Order\Status;
use App\Enums\Order\StoreType;
use App\Events\OrderCreated;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\ProductVariant;
use App\Repositories\OrderDetailRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductVariantRepository;
use App\Services\OrderService;
use App\Stores\CartStore;
use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 建立訂單: 應建立訂單、明細、扣庫存、清空購物車並 dispatch OrderCreated。
     */
    public function test_store_order_creates_order_details_decrements_stock_clears_cart_and_dispatches_event(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        $productVariant = $this->createProductVariant([
            'product_name' => 'Cotton Shirt',
            'color' => '黑',
            'size' => 3,
            'sku' => 'TSHIRT-BLACK-M',
            'price' => 800,
            'stock_quantity' => 5,
        ]);

        app(CartStore::class)->storeItem($member->id, $productVariant->id, 2);

        $result = app(OrderService::class)->storeOrder(
            memberId: $member->id,
            idempotencyKey: '01J3QS2AJMZV09DNXQ2EE4NM2E',
            paymentMethod: PaymentMethod::CREDIT_CARD->value,
            shippingMethod: ShippingMethod::CONVENIENCE_STORE->value,
            recipientData: $this->recipientData(address: null),
            storeData: [
                'code' => 'UNIMART001',
                'type' => StoreType::UNIMART->value,
                'name' => '信義門市',
                'address' => '台北市信義區測試路 1 號',
            ],
        );

        $this->assertSame(201, $result['status']);
        $this->assertSame('訂單已建立。', $result['message']);
        $this->assertSame(1600, $result['data']['total_amount']);
        $this->assertSame(77, $result['data']['tax_amount']);
        $this->assertSame(0, $result['data']['shipping_fee']);
        $this->assertSame(ShippingMethod::CONVENIENCE_STORE->value, $result['data']['shipping_method']);
        $this->assertSame(StoreType::UNIMART->value, $result['data']['store_type']);
        $this->assertSame('UNIMART001', $result['data']['store_code']);
        $this->assertSame('信義門市', $result['data']['store_name']);
        $this->assertSame('台北市信義區測試路 1 號', $result['data']['store_address']);
        $this->assertSame(Status::STOCKING->value, $result['data']['status']);
        $this->assertSame(PaymentStatus::UNPAID->value, $result['data']['payment_status']);
        $this->assertSame([
            'product_variant_id' => $productVariant->id,
            'product_name' => 'Cotton Shirt',
            'product_sku' => 'TSHIRT-BLACK-M',
            'product_color' => '黑',
            'product_size' => 3,
            'product_price' => 800,
            'quantity' => 2,
            'subtotal' => 1600,
        ], $result['data']['items'][0]);
        $this->assertSame(StoreType::UNIMART->value, $result['data']['store_type']);
        $this->assertSame('信義門市', $result['data']['store_name']);
        $this->assertSame('台北市信義區測試路 1 號', $result['data']['store_address']);

        $orderId = $result['data']['id'];
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'member_id' => $member->id,
            'idempotency_key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
            'total_amount' => 1600,
            'tax_amount' => 77,
            'shipping_fee' => 0,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'store_type' => StoreType::UNIMART->value,
            'store_code' => 'UNIMART001',
            'store_name' => '信義門市',
            'store_address' => '台北市信義區測試路 1 號',
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        $this->assertDatabaseHas('order_details', [
            'order_id' => $orderId,
            'product_variant_id' => $productVariant->id,
            'product_sku' => 'TSHIRT-BLACK-M',
            'quantity' => 2,
            'subtotal' => 1600,
        ]);
        $this->assertSame(3, $productVariant->refresh()->stock_quantity);
        $this->assertSame([], app(CartStore::class)->getItems($member->id));
        Event::assertDispatched(OrderCreated::class, fn (OrderCreated $event): bool => $event->orderId === $orderId);
    }

    /**
     * 建立訂單: 未達免運門檻時應加上運費。
     */
    public function test_store_order_adds_shipping_fee_when_items_subtotal_is_below_free_shipping_threshold(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        $productVariant = $this->createProductVariant([
            'price' => 600,
            'stock_quantity' => 3,
        ]);

        app(CartStore::class)->storeItem($member->id, $productVariant->id, 1);

        $result = app(OrderService::class)->storeOrder(
            memberId: $member->id,
            idempotencyKey: '01J3QS2AJMZV09DNXQ2EE4NM2F',
            paymentMethod: PaymentMethod::CREDIT_CARD->value,
            recipientData: $this->recipientData(),
        );

        $this->assertSame(201, $result['status']);
        $this->assertSame(680, $result['data']['total_amount']);
        $this->assertSame(29, $result['data']['tax_amount']);
        $this->assertSame(80, $result['data']['shipping_fee']);
    }

    /**
     * 建立訂單: 多筆購物車項目應依 product_variant_id 排序、累加總額並分別扣庫存。
     */
    public function test_store_order_creates_multiple_items_in_product_variant_id_order(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        $firstVariant = $this->createProductVariant([
            'product_name' => 'First Product',
            'sku' => 'FIRST-SKU',
            'price' => 300,
            'stock_quantity' => 5,
        ]);
        $secondVariant = $this->createProductVariant([
            'product_name' => 'Second Product',
            'sku' => 'SECOND-SKU',
            'price' => 400,
            'stock_quantity' => 5,
        ]);

        app(CartStore::class)->storeItem($member->id, $secondVariant->id, 1);
        app(CartStore::class)->storeItem($member->id, $firstVariant->id, 2);

        $result = app(OrderService::class)->storeOrder(
            memberId: $member->id,
            idempotencyKey: '01J3QS2AJMZV09DNXQ2EE4NM2O',
            paymentMethod: PaymentMethod::CREDIT_CARD->value,
            recipientData: $this->recipientData(),
        );

        $this->assertSame(201, $result['status']);
        $this->assertSame(1000, $result['data']['total_amount']);
        $this->assertSame(48, $result['data']['tax_amount']);
        $this->assertSame(0, $result['data']['shipping_fee']);
        $this->assertSame($firstVariant->id, $result['data']['items'][0]['product_variant_id']);
        $this->assertSame($secondVariant->id, $result['data']['items'][1]['product_variant_id']);
        $this->assertSame(3, $firstVariant->refresh()->stock_quantity);
        $this->assertSame(4, $secondVariant->refresh()->stock_quantity);
    }

    /**
     * 建立訂單: Idempotency-Key 已有訂單時應直接回傳既有訂單，不重新 dispatch event。
     */
    public function test_store_order_returns_existing_order_when_idempotency_key_already_exists(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        $order = Order::factory()->for($member)->create([
            'idempotency_key' => '01J3QS2AJMZV09DNXQ2EE4NM2G',
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        OrderDetail::factory()->for($order)->create();

        $result = app(OrderService::class)->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2G',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('訂單已存在。', $result['message']);
        $this->assertSame($order->id, $result['data']['id']);
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 建立訂單: transaction 內重新檢查到既有訂單時應回傳既有訂單，不繼續扣庫存。
     */
    public function test_store_order_returns_existing_order_when_transaction_recheck_finds_order(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        $order = Order::factory()->for($member)->create([
            'idempotency_key' => '01J3QS2AJMZV09DNXQ2EE4NM2R',
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        OrderDetail::factory()->for($order)->create();
        $productVariant = $this->createProductVariant();
        app(CartStore::class)->storeItem($member->id, $productVariant->id, 1);

        $conditions = [
            ['member_id', $member->id],
            ['idempotency_key', '01J3QS2AJMZV09DNXQ2EE4NM2R'],
        ];
        $orderRepository = Mockery::mock(OrderRepository::class);
        $orderRepository->shouldReceive('first')
            ->once()
            ->with($conditions)
            ->andReturn(null)
            ->ordered();
        $orderRepository->shouldReceive('first')
            ->once()
            ->with($conditions)
            ->andReturn($order)
            ->ordered();
        $orderRepository->shouldReceive('create')->never();

        $productVariantRepository = Mockery::mock(ProductVariantRepository::class);
        $productVariantRepository->shouldReceive('first')->never();
        $productVariantRepository->shouldReceive('updateStockQuantity')->never();

        $result = $this->makeOrderService(
            orderRepository: $orderRepository,
            productVariantRepository: $productVariantRepository,
        )->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2R',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('訂單已存在。', $result['message']);
        $this->assertSame($order->id, $result['data']['id']);
        $this->assertSame(10, $productVariant->refresh()->stock_quantity);
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 建立訂單: 購物車為空時應回傳 409。
     */
    public function test_store_order_returns_409_when_cart_is_empty(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();

        $result = app(OrderService::class)->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2H',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(409, $result['status']);
        $this->assertSame('購物車沒有可建立訂單的產品。', $result['message']);
        $this->assertSame(0, Order::query()->where('member_id', $member->id)->count());
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 建立訂單: 購物車包含不存在的產品規格時應回傳 409。
     */
    public function test_store_order_returns_409_when_cart_contains_missing_product_variant(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        app(CartStore::class)->storeItem($member->id, 999999, 1);

        $result = app(OrderService::class)->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2I',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(409, $result['status']);
        $this->assertSame('購物車包含不存在的產品。', $result['message']);
        $this->assertSame(0, Order::query()->where('member_id', $member->id)->count());
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 建立訂單: 庫存不足時應回傳 409 且不扣庫存。
     */
    public function test_store_order_returns_409_when_stock_is_insufficient(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        $productVariant = $this->createProductVariant([
            'stock_quantity' => 1,
        ]);
        app(CartStore::class)->storeItem($member->id, $productVariant->id, 2);

        $result = app(OrderService::class)->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2J',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(409, $result['status']);
        $this->assertSame('產品庫存不足。', $result['message']);
        $this->assertSame(1, $productVariant->refresh()->stock_quantity);
        $this->assertSame(0, Order::query()->where('member_id', $member->id)->count());
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 建立訂單: 原子扣庫存失敗時應回傳 409。
     */
    public function test_store_order_returns_409_when_atomic_stock_decrement_fails(): void
    {
        $member = Member::factory()->create();
        $productVariant = $this->createProductVariant([
            'stock_quantity' => 5,
        ]);
        app(CartStore::class)->storeItem($member->id, $productVariant->id, 2);

        $productVariantRepository = Mockery::mock(ProductVariantRepository::class);
        $productVariantRepository->shouldReceive('first')
            ->once()
            ->with(['id', $productVariant->id])
            ->andReturn($productVariant);
        $productVariantRepository->shouldReceive('updateStockQuantity')
            ->once()
            ->with($productVariant->id, 2)
            ->andReturn(0);

        $result = $this->makeOrderService(productVariantRepository: $productVariantRepository)->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2K',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(409, $result['status']);
        $this->assertSame('產品庫存不足。', $result['message']);
    }

    /**
     * 建立訂單: 建立訂單明細失敗時應回傳 503。
     */
    public function test_store_order_returns_503_when_order_details_cannot_be_created(): void
    {
        $member = Member::factory()->create();
        $productVariant = $this->createProductVariant([
            'stock_quantity' => 5,
        ]);
        app(CartStore::class)->storeItem($member->id, $productVariant->id, 2);

        $orderDetailRepository = Mockery::mock(OrderDetailRepository::class);
        $orderDetailRepository->shouldReceive('createMany')
            ->once()
            ->andReturn(false);

        $result = $this->makeOrderService(orderDetailRepository: $orderDetailRepository)->storeOrder(
            memberId: $member->id,
            idempotencyKey: '01J3QS2AJMZV09DNXQ2EE4NM2L',
            paymentMethod: PaymentMethod::CREDIT_CARD->value,
            recipientData: $this->recipientData(),
        );

        $this->assertSame(503, $result['status']);
        $this->assertSame('訂單建立失敗，請稍後再試。', $result['message']);
    }

    /**
     * 建立訂單: unique constraint collision 後若可找到既有訂單，應回傳既有訂單。
     */
    public function test_store_order_returns_existing_order_when_unique_constraint_collision_finds_existing_order(): void
    {
        Event::fake([OrderCreated::class]);

        $member = Member::factory()->create();
        $existingOrder = Order::factory()->for($member)->create([
            'number' => 'ORD20260905DUP001',
            'idempotency_key' => '01J3QS2AJMZV09DNXQ2EE4NM2S',
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        OrderDetail::factory()->for($existingOrder)->create();
        $duplicateException = $this->makeDuplicateOrderQueryException($member);
        $productVariant = $this->createProductVariant([
            'stock_quantity' => 5,
        ]);
        app(CartStore::class)->storeItem($member->id, $productVariant->id, 1);

        $conditions = [
            ['member_id', $member->id],
            ['idempotency_key', '01J3QS2AJMZV09DNXQ2EE4NM2S'],
        ];
        $orderRepository = Mockery::mock(OrderRepository::class);
        $orderRepository->shouldReceive('first')
            ->once()
            ->with($conditions)
            ->andReturn(null)
            ->ordered();
        $orderRepository->shouldReceive('first')
            ->once()
            ->with($conditions)
            ->andReturn(null)
            ->ordered();
        $orderRepository->shouldReceive('exists')
            ->once()
            ->andReturn(false);
        $orderRepository->shouldReceive('create')
            ->once()
            ->andThrow($duplicateException);
        $orderRepository->shouldReceive('first')
            ->once()
            ->with($conditions)
            ->andReturn($existingOrder)
            ->ordered();

        $result = $this->makeOrderService(orderRepository: $orderRepository)->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2S',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(200, $result['status']);
        $this->assertSame('訂單已存在。', $result['message']);
        $this->assertSame($existingOrder->id, $result['data']['id']);
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 建立訂單: unique constraint collision 後若找不到既有訂單，應記錄 error 並回傳 503。
     */
    public function test_store_order_returns_503_when_unique_constraint_collision_cannot_find_existing_order(): void
    {
        $member = Member::factory()->create();
        Order::factory()->for($member)->create([
            'number' => 'ORD20260905DUP002',
            'idempotency_key' => '01J3QS2AJMZV09DNXQ2EE4NM2T',
        ]);
        $duplicateException = $this->makeDuplicateOrderQueryException($member, 'ORD20260905DUP002');
        $productVariant = $this->createProductVariant([
            'stock_quantity' => 5,
        ]);
        app(CartStore::class)->storeItem($member->id, $productVariant->id, 1);

        $conditions = [
            ['member_id', $member->id],
            ['idempotency_key', '01J3QS2AJMZV09DNXQ2EE4NM2U'],
        ];
        $orderRepository = Mockery::mock(OrderRepository::class);
        $orderRepository->shouldReceive('first')
            ->once()
            ->with($conditions)
            ->andReturn(null)
            ->ordered();
        $orderRepository->shouldReceive('first')
            ->once()
            ->with($conditions)
            ->andReturn(null)
            ->ordered();
        $orderRepository->shouldReceive('exists')
            ->once()
            ->andReturn(false);
        $orderRepository->shouldReceive('create')
            ->once()
            ->andThrow($duplicateException);
        $orderRepository->shouldReceive('first')
            ->once()
            ->with($conditions)
            ->andReturn(null)
            ->ordered();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('建立訂單失敗', Mockery::on(fn (array $context): bool => $context['member_id'] === $member->id));

        $result = $this->makeOrderService(
            orderRepository: $orderRepository,
            logger: $logger,
        )->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2U',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(503, $result['status']);
        $this->assertSame('訂單建立失敗，請稍後再試。', $result['message']);
    }

    /**
     * 建立訂單: 非唯一索引的資料庫錯誤應記錄 error 並回傳 503。
     */
    public function test_store_order_returns_503_when_query_exception_is_not_unique_constraint_collision(): void
    {
        $member = Member::factory()->create();
        $productVariant = $this->createProductVariant([
            'stock_quantity' => 5,
        ]);
        app(CartStore::class)->storeItem($member->id, $productVariant->id, 1);

        $orderRepository = Mockery::mock(OrderRepository::class);
        $orderRepository->shouldReceive('first')
            ->twice()
            ->andReturn(null);
        $orderRepository->shouldReceive('exists')
            ->once()
            ->andReturn(false);
        $orderRepository->shouldReceive('create')
            ->once()
            ->andThrow(new QueryException('mysql', 'insert into orders', [], new Exception('Database failure', 999)));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('建立訂單失敗', Mockery::on(fn (array $context): bool => $context['member_id'] === $member->id));

        $result = $this->makeOrderService(
            orderRepository: $orderRepository,
            logger: $logger,
        )->storeOrder(
            $member->id,
            '01J3QS2AJMZV09DNXQ2EE4NM2V',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(503, $result['status']);
        $this->assertSame('訂單建立失敗，請稍後再試。', $result['message']);
    }

    /**
     * 建立訂單: 無法取得 checkout lock 時應回傳 409。
     */
    public function test_store_order_returns_409_when_checkout_lock_times_out(): void
    {
        $lock = Mockery::mock();
        $lock->shouldReceive('block')
            ->once()
            ->with(3, Mockery::type('Closure'))
            ->andThrow(new LockTimeoutException('Lock timeout'));

        $cache = Mockery::mock(CacheManager::class);
        $cache->shouldReceive('lock')
            ->once()
            ->with('checkout:member:123', 10)
            ->andReturn($lock);

        $result = $this->makeOrderService(cache: $cache)->storeOrder(
            123,
            '01J3QS2AJMZV09DNXQ2EE4NM2M',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(409, $result['status']);
        $this->assertSame('訂單建立中，請稍後再試。', $result['message']);
    }

    /**
     * 建立訂單: 非預期錯誤時應記錄 error 並回傳 503。
     */
    public function test_store_order_returns_503_when_unexpected_exception_occurs(): void
    {
        $cartStore = Mockery::mock(CartStore::class);
        $cartStore->shouldReceive('getItems')
            ->once()
            ->with(123)
            ->andThrow(new RuntimeException('Unexpected cart failure'));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->once()
            ->with('建立訂單失敗', Mockery::on(fn (array $context): bool => $context['member_id'] === 123));

        $result = $this->makeOrderService(
            cartStore: $cartStore,
            logger: $logger,
        )->storeOrder(
            123,
            '01J3QS2AJMZV09DNXQ2EE4NM2N',
            PaymentMethod::CREDIT_CARD->value,
        );

        $this->assertSame(503, $result['status']);
        $this->assertSame('訂單建立失敗，請稍後再試。', $result['message']);
    }

    private function makeOrderService(
        ?OrderRepository $orderRepository = null,
        ?OrderDetailRepository $orderDetailRepository = null,
        ?ProductVariantRepository $productVariantRepository = null,
        ?CartStore $cartStore = null,
        ?ConnectionInterface $db = null,
        ?LoggerInterface $logger = null,
        ?CacheManager $cache = null,
        ?Dispatcher $events = null,
    ): OrderService {
        return new OrderService(
            $orderRepository ?? new OrderRepository,
            $orderDetailRepository ?? new OrderDetailRepository,
            $productVariantRepository ?? new ProductVariantRepository,
            $cartStore ?? app(CartStore::class),
            $db ?? app(ConnectionInterface::class),
            $logger ?? app(LoggerInterface::class),
            $cache ?? app(CacheManager::class),
            $events ?? app(Dispatcher::class),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createProductVariant(array $attributes = []): ProductVariant
    {
        $product = Product::factory()->create([
            'name' => $attributes['product_name'] ?? 'Test Product',
        ]);
        $productSpec = ProductSpec::factory()->create([
            'color' => $attributes['color'] ?? '紅',
            'size' => $attributes['size'] ?? 1,
        ]);

        return ProductVariant::factory()->create([
            'product_id' => $product->id,
            'product_spec_id' => $productSpec->id,
            'sku' => $attributes['sku'] ?? 'SKU-TEST-001',
            'price' => $attributes['price'] ?? 500,
            'stock_quantity' => $attributes['stock_quantity'] ?? 10,
        ]);
    }

    private function makeDuplicateOrderQueryException(Member $member, string $orderNumber = 'ORD20260905DUP001'): QueryException
    {
        try {
            Order::factory()->for($member)->create([
                'number' => $orderNumber,
            ]);
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Expected duplicate order number to throw a query exception.');
    }

    /**
     * @return array{name: string, phone: string, zip_code: ?string, address: ?string}
     */
    private function recipientData(?string $address = '台北市信義區測試路 1 號', ?string $zipCode = '100'): array
    {
        return [
            'name' => '王小明',
            'phone' => '0912345678',
            'zip_code' => $zipCode,
            'address' => $address,
        ];
    }
}
