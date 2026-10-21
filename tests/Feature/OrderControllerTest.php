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
use App\Enums\ProductSpec\Size;
use App\Events\OrderCreated;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\ProductVariant;
use App\Models\Shipment;
use App\Notifications\OrderCreatedNotification;
use App\Repositories\OrderRepository;
use App\Services\OrderService;
use App\Stores\CartStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 訂單詳情: 未登入時不可查看訂單，即使指定的訂單不存在也應先回傳 401。
     */
    public function test_guest_cannot_view_order_details(): void
    {
        $order = Order::factory()->create();

        $this->getJson("/api/orders/{$order->id}")->assertUnauthorized();
        $this->getJson('/api/orders/999999')->assertUnauthorized();
    }

    /**
     * 訂單詳情: 他人的訂單與不存在的訂單皆回傳 404，不可信任 client 指定的 member_id。
     */
    public function test_show_returns_404_for_another_members_order_and_a_missing_order(): void
    {
        Sanctum::actingAs(Member::factory()->create());
        $otherOrder = Order::factory()->create();

        $this->getJson("/api/orders/{$otherOrder->id}?member_id={$otherOrder->member_id}")
            ->assertNotFound();
        $this->getJson('/api/orders/999999')->assertNotFound();
    }

    /**
     * 訂單詳情: 已有本人訂單時，非法路徑識別值仍須回傳 404，不得轉型後誤取有效訂單。
     */
    #[DataProvider('invalidOrderIdentifierProvider')]
    public function test_show_returns_404_for_invalid_order_identifiers(string $identifier): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();
        $identifier = str_replace('{order_id}', (string) $order->id, $identifier);

        $this->getJson("/api/orders/{$identifier}")->assertNotFound();
    }

    public static function invalidOrderIdentifierProvider(): array
    {
        return [
            'text' => ['not-an-order'],
            'negative' => ['-1'],
            'zero' => ['0'],
            'fraction' => ['{order_id}.5'],
        ];
    }

    /**
     * 訂單操作路由: 非數字 ID 在驗證身分前回傳 404，不得誤取有效訂單或建立付款交易。
     */
    public function test_order_action_routes_reject_non_numeric_identifiers(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->for($member)->create();
        $before = $order->refresh()->getAttributes();

        $this->getJson('/api/orders/not-an-order')->assertNotFound();
        $this->patchJson('/api/orders/not-an-order/cancel')->assertNotFound();
        $this->getJson('/api/orders/not-an-order/payment-checkout')->assertNotFound();
        $this->postJson('/api/orders/not-an-order/payment-transactions')->assertNotFound();

        Sanctum::actingAs($member);

        foreach (['not-an-order', "-{$order->id}", "{$order->id}.5"] as $identifier) {
            $this->patchJson("/api/orders/{$identifier}/cancel")->assertNotFound();
            $this->getJson("/api/orders/{$identifier}/payment-checkout")->assertNotFound();
            $this->postJson("/api/orders/{$identifier}/payment-transactions")->assertNotFound();
        }

        $this->assertSame($before, $order->refresh()->getAttributes());
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    /**
     * 訂單詳情: 僅公開約定欄位，商品明細與收件資訊皆來自快照，且稅額不得重複加總。
     */
    public function test_show_returns_the_exact_order_detail_contract_and_original_types(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'number' => 'ORD20261012AB12CD',
            'created_at' => '2026-10-12 13:30:00',
            'status' => Status::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'total_amount' => 880,
            'tax_amount' => 39,
            'shipping_fee' => 80,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市測試路 1 號',
        ]);
        $detail = OrderDetail::factory()->for($order)->create([
            'product_name' => '經典上衣',
            'product_sku' => 'TOP-BLACK-M',
            'product_color' => '黑色',
            'product_size' => Size::M->value,
            'product_price' => 400,
            'quantity' => 2,
            'subtotal' => 800,
        ]);
        PaymentTransaction::factory()->for($order)->count(2)->create();
        Shipment::factory()->for($order)->count(2)->create();

        $expected = [
            'id' => $order->id,
            'number' => 'ORD20261012AB12CD',
            'created_at' => $order->created_at->toISOString(),
            'status' => Status::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'item_quantity' => 2,
            'items_subtotal' => 800,
            'shipping_fee' => 80,
            'tax_amount' => 39,
            'total_amount' => 880,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市測試路 1 號',
            'store_type' => null,
            'store_code' => null,
            'store_name' => null,
            'store_address' => null,
            'items' => [[
                'product_variant_id' => $detail->product_variant_id,
                'product_name' => '經典上衣',
                'product_sku' => 'TOP-BLACK-M',
                'product_color' => '黑色',
                'product_size' => Size::M->value,
                'product_price' => 400,
                'quantity' => 2,
                'subtotal' => 800,
            ]],
        ];

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()->assertExactJson(['data' => $expected]);

        foreach ($expected as $field => $value) {
            if ($field !== 'items') {
                $response->assertJsonPath("data.{$field}", $value);
            }
        }

        foreach ($expected['items'][0] as $field => $value) {
            $response->assertJsonPath("data.items.0.{$field}", $value);
        }
    }

    /**
     * 訂單詳情: 多筆明細須分別回傳並加總件數與小計，不得混入同一會員其他訂單或他人明細。
     */
    public function test_show_aggregates_only_the_requested_orders_details(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'total_amount' => 1500, 'shipping_fee' => 0, 'tax_amount' => 72,
        ]);
        $first = OrderDetail::factory()->for($order)->create([
            'product_price' => 300, 'quantity' => 2, 'subtotal' => 600,
        ]);
        $second = OrderDetail::factory()->for($order)->create([
            'product_price' => 300, 'quantity' => 3, 'subtotal' => 900,
        ]);
        $anotherOrder = Order::factory()->for($member)->create();
        OrderDetail::factory()->for($anotherOrder)->create(['quantity' => 8, 'subtotal' => 8000]);
        OrderDetail::factory()->create(['quantity' => 9, 'subtotal' => 9000]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.item_quantity', 5)
            ->assertJsonPath('data.items_subtotal', 1500)
            ->assertJsonPath('data.shipping_fee', 0)
            ->assertJsonPath('data.tax_amount', 72)
            ->assertJsonPath('data.total_amount', 1500);
        $this->assertEqualsCanonicalizing(
            [$first->product_variant_id, $second->product_variant_id],
            array_column($response->json('data.items'), 'product_variant_id'),
        );
    }

    /**
     * 訂單詳情: 明細與商品小計皆使用已保存的 subtotal，不以單價乘數量重新計算歷史金額。
     */
    public function test_show_uses_stored_item_subtotals_instead_of_recalculating_them(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'total_amount' => 1430, 'shipping_fee' => 80, 'tax_amount' => 65,
        ]);
        $first = OrderDetail::factory()->for($order)->create([
            'product_price' => 300, 'quantity' => 2, 'subtotal' => 550,
        ]);
        $second = OrderDetail::factory()->for($order)->create([
            'product_price' => 300, 'quantity' => 3, 'subtotal' => 800,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.item_quantity', 5)
            ->assertJsonPath('data.items_subtotal', 1350)
            ->assertJsonPath('data.shipping_fee', 80)
            ->assertJsonPath('data.tax_amount', 65)
            ->assertJsonPath('data.total_amount', 1430);
        $subtotals = array_column($response->json('data.items'), 'subtotal', 'product_variant_id');
        $this->assertSame(550, $subtotals[$first->product_variant_id]);
        $this->assertSame(800, $subtotals[$second->product_variant_id]);
    }

    /**
     * 訂單詳情: 無明細時仍可讀取已儲存的訂單金額，衍生加總須為整數零而非 null。
     */
    public function test_show_returns_empty_items_and_zero_aggregates_without_recalculating_stored_amounts(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'total_amount' => 880, 'shipping_fee' => 80, 'tax_amount' => 39,
        ]);

        $this->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.item_quantity', 0)
            ->assertJsonPath('data.items_subtotal', 0)
            ->assertJsonPath('data.total_amount', 880)
            ->assertJsonPath('data.shipping_fee', 80)
            ->assertJsonPath('data.tax_amount', 39);
    }

    /**
     * 訂單詳情: 各超商類型皆須保留門市快照與 nullable 宅配欄位，不另改為巢狀收件結構。
     */
    #[DataProvider('orderDetailStoreTypeProvider')]
    public function test_show_returns_convenience_store_snapshots(string $storeType): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'recipient_zip_code' => null,
            'recipient_address' => null,
            'store_type' => $storeType,
            'store_code' => '123456',
            'store_name' => '測試門市',
            'store_address' => '台北市門市路 1 號',
        ]);

        $this->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.shipping_method', ShippingMethod::CONVENIENCE_STORE->value)
            ->assertJsonPath('data.recipient_name', $order->recipient_name)
            ->assertJsonPath('data.recipient_phone', $order->recipient_phone)
            ->assertJsonPath('data.recipient_zip_code', null)
            ->assertJsonPath('data.recipient_address', null)
            ->assertJsonPath('data.store_type', $storeType)
            ->assertJsonPath('data.store_code', '123456')
            ->assertJsonPath('data.store_name', '測試門市')
            ->assertJsonPath('data.store_address', '台北市門市路 1 號');
    }

    public static function orderDetailStoreTypeProvider(): array
    {
        return array_map(fn (StoreType $type): array => [$type->value], StoreType::cases());
    }

    /**
     * 訂單詳情: 商品、規格與會員資料修改後，仍顯示原始商品、金額及收件快照。
     */
    public function test_show_preserves_snapshots_after_product_spec_and_member_changes(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();
        $detail = OrderDetail::factory()->for($order)->create();
        $variant = $detail->productVariant;
        $variant->product->update(['name' => '修改後的商品']);
        $variant->productSpec->update(['color' => '白色', 'size' => Size::XL->value]);
        $variant->update(['sku' => 'CHANGED-SKU', 'price' => 99999]);
        $member->forceFill(['phone' => '0987654321', 'address' => '修改後的會員地址'])->save();

        $response = $this->getJson("/api/orders/{$order->id}");

        $response->assertOk()
            ->assertJsonPath('data.recipient_name', $order->recipient_name)
            ->assertJsonPath('data.recipient_phone', $order->recipient_phone)
            ->assertJsonPath('data.recipient_zip_code', $order->recipient_zip_code)
            ->assertJsonPath('data.recipient_address', $order->recipient_address)
            ->assertJsonPath('data.total_amount', $order->total_amount)
            ->assertJsonPath('data.shipping_fee', $order->shipping_fee)
            ->assertJsonPath('data.tax_amount', $order->tax_amount);

        foreach (['product_name', 'product_sku', 'product_color', 'product_size', 'product_price', 'quantity', 'subtotal'] as $field) {
            $response->assertJsonPath("data.items.0.{$field}", $detail->{$field});
        }
    }

    /**
     * 訂單詳情: 商品與規格商品已軟刪除時，歷史訂單仍須透過明細快照正常呈現。
     */
    public function test_show_preserves_items_when_the_product_and_variant_are_soft_deleted(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();
        $detail = OrderDetail::factory()->for($order)->create();
        $variant = $detail->productVariant;
        $variant->delete();
        $variant->product->delete();

        $this->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.product_variant_id', $detail->product_variant_id)
            ->assertJsonPath('data.items.0.product_name', $detail->product_name)
            ->assertJsonPath('data.items.0.product_price', $detail->product_price)
            ->assertJsonPath('data.items_subtotal', $detail->subtotal);
    }

    /**
     * 訂單詳情: 舊明細未保存的 SKU、顏色與尺寸應維持 null，不以目前商品資料補值。
     */
    public function test_show_preserves_null_optional_item_snapshot_fields(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();
        OrderDetail::factory()->for($order)->create([
            'product_sku' => null, 'product_color' => null, 'product_size' => null,
        ]);

        $this->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.items.0.product_sku', null)
            ->assertJsonPath('data.items.0.product_color', null)
            ->assertJsonPath('data.items.0.product_size', null);
    }

    /**
     * 訂單詳情: 包含取消、完成與退款等所有狀態，Enum 欄位皆保留整數值而非中文。
     */
    #[DataProvider('orderSummaryEnumProvider')]
    public function test_show_preserves_each_order_enum_value(string $field, int $value): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([$field => $value]);

        $this->getJson("/api/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath("data.{$field}", $value);
    }

    /**
     * 訂單詳情: 讀取十筆明細時不得逐筆查詢目前商品或規格，避免 N+1 與歷史資料依賴。
     */
    public function test_show_loads_multiple_items_without_per_item_queries(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();
        OrderDetail::factory()->for($order)->count(10)->create();
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $response = $this->getJson("/api/orders/{$order->id}");
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $response->assertOk()->assertJsonCount(10, 'data.items');
        $detailQueries = array_filter($queries, fn (array $query): bool => str_starts_with(
            strtolower(ltrim($query['query'])), 'select'
        ) && preg_match('/order_details|products|product_variants|product_specs/i', $query['query']));
        $this->assertLessThanOrEqual(3, count($detailQueries), 'Snapshot items must not trigger per-item queries.');
    }

    /**
     * 訂單詳情: 重複讀取不修改訂單、明細或庫存，也不建立付款交易、物流或派送建立訂單事件。
     */
    public function test_show_is_read_only_when_requested_repeatedly(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();
        $detail = OrderDetail::factory()->for($order)->create();
        $variant = $detail->productVariant;
        $before = [
            $order->refresh()->getAttributes(),
            $detail->refresh()->getAttributes(),
            $variant->refresh()->getAttributes(),
        ];
        Event::fake([OrderCreated::class]);

        $this->getJson("/api/orders/{$order->id}")->assertOk();
        $this->getJson("/api/orders/{$order->id}")->assertOk();

        $this->assertSame($before, [
            $order->refresh()->getAttributes(),
            $detail->refresh()->getAttributes(),
            $variant->refresh()->getAttributes(),
        ]);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_details', 1);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('shipments', 0);
        Event::assertNotDispatched(OrderCreated::class);
    }

    /**
     * 訂單詳情: 明細查詢失敗時回傳通用 500，不洩漏 SQL 或將失敗當作空明細。
     */
    public function test_show_returns_a_generic_500_when_loading_details_fails(): void
    {
        config()->set('app.debug', false);
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();
        OrderDetail::factory()->for($order)->create();
        $queryAttempted = false;
        DB::listen(function (QueryExecuted $query) use (&$queryAttempted): void {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'select')
                && str_contains(strtolower($query->sql), 'order_details')) {
                $queryAttempted = true;
                throw new QueryException(
                    $query->connectionName,
                    $query->sql,
                    $query->bindings,
                    new \RuntimeException('Internal order detail query failure'),
                );
            }
        });

        $response = $this->getJson("/api/orders/{$order->id}");

        $this->assertTrue($queryAttempted, 'The request must reach the detail query, not fail because show() is missing.');
        $response->assertInternalServerError()->assertExactJson(['message' => 'Server Error']);
    }

    /**
     * 訂單詳情: 套用既有訂單讀取限流，每分鐘第六十一次請求須回傳 429 與等待時間。
     */
    public function test_show_is_rate_limited_after_sixty_requests_per_minute(): void
    {
        $this->freezeTime();
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->getJson("/api/orders/{$order->id}")->assertOk();
        }

        $this->getJson("/api/orders/{$order->id}")->assertTooManyRequests()->assertHeader('Retry-After');
    }

    /**
     * 訂單列表: 未驗證的訪客應收到 401，無法查詢會員訂單。
     */
    public function test_guest_cannot_list_orders(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }

    /**
     * 訂單列表: 查詢失敗時應回傳 500，不可偽裝成空列表，且關閉 debug 時不得洩漏 SQL 或內部錯誤。
     */
    public function test_index_returns_a_generic_500_response_when_the_order_query_fails(): void
    {
        config()->set('app.debug', false);
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        Order::factory()->for($member)->create();

        $exception = new QueryException(
            'mysql',
            'select * from orders where member_id = ?',
            [$member->id],
            new \RuntimeException('Internal database connection failure'),
        );
        $orderRepository = Mockery::mock(OrderRepository::class);
        $orderRepository->shouldReceive('getOrders')
            ->once()
            ->with($member->id, 2, 3)
            ->andThrow($exception);
        $this->app->instance(OrderRepository::class, $orderRepository);

        $this->getJson('/api/orders?row_counts_per_page=2&page=3')
            ->assertInternalServerError()
            ->assertExactJson([
                'message' => 'Server Error',
            ]);
    }

    /**
     * 訂單列表: 僅公開九個摘要欄位並保留型別，避免洩漏個資或金流資料及因多筆關聯產生重複訂單。
     */
    public function test_index_returns_only_the_nine_order_summary_fields_with_original_types(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'number' => 'ORD20261012AB12CD',
            'total_amount' => 1280,
            'status' => Status::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'created_at' => '2026-10-12 13:30:00',
        ]);
        OrderDetail::factory()->for($order)->count(2)->create(['quantity' => 3]);
        PaymentTransaction::factory()->for($order)->count(2)->create();
        Shipment::factory()->for($order)->count(2)->create();

        $response = $this->getJson('/api/orders');

        $expected = [
            'id' => $order->id,
            'number' => 'ORD20261012AB12CD',
            'created_at' => $order->created_at->toISOString(),
            'total_amount' => 1280,
            'status' => Status::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'item_quantity' => 6,
        ];

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($response->json('data.0')));

        foreach ($expected as $field => $value) {
            $response->assertJsonPath("data.0.{$field}", $value);
        }
    }

    /**
     * 訂單列表: 資料與總筆數皆以登入會員為準，不可信任 client 傳入的 member_id。
     */
    public function test_index_scopes_both_orders_and_pagination_totals_to_the_authenticated_member(): void
    {
        $member = Member::factory()->create();
        $otherMember = Member::factory()->create();
        $orders = Order::factory()->for($member)->count(3)->create([
            'created_at' => '2026-10-10 12:00:00',
        ]);
        Order::factory()->for($otherMember)->count(5)->create([
            'created_at' => '2026-10-12 12:00:00',
        ]);
        Sanctum::actingAs($member);

        $response = $this->getJson("/api/orders?row_counts_per_page=2&member_id={$otherMember->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $orders[2]->id)
            ->assertJsonPath('data.1.id', $orders[1]->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    /**
     * 訂單列表: 會員沒有訂單時仍回傳 200 與空分頁，不可帶出其他會員的訂單。
     */
    public function test_index_returns_an_empty_paginated_collection_when_member_has_no_orders(): void
    {
        Order::factory()->count(2)->create();
        Sanctum::actingAs(Member::factory()->create());

        $this->getJson('/api/orders')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.from', null)
            ->assertJsonPath('meta.to', null)
            ->assertJsonPath('links.prev', null)
            ->assertJsonPath('links.next', null);
    }

    /**
     * 訂單列表: 未指定分頁時使用每頁十筆與第一頁，並提供標準 data、links、meta 結構。
     */
    public function test_index_uses_ten_orders_per_page_and_page_one_by_default(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        Order::factory()->for($member)->count(12)->create();

        $this->getJson('/api/orders')->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 10)
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => [
                    'current_page', 'from', 'last_page', 'links',
                    'path', 'per_page', 'to', 'total',
                ],
            ]);
    }

    /**
     * 訂單列表: 自訂分頁應取得正確資料，分頁連結須保留每頁筆數以避免切頁時改變範圍。
     */
    public function test_index_returns_the_requested_page_and_links_preserve_page_size(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $orders = Order::factory()->for($member)->count(7)->create([
            'created_at' => '2026-10-12 12:00:00',
        ]);

        $response = $this->getJson('/api/orders?row_counts_per_page=2&page=2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $orders[4]->id)
            ->assertJsonPath('data.1.id', $orders[3]->id)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.last_page', 4)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonPath('meta.to', 4);

        foreach (['first' => 1, 'last' => 4, 'prev' => 1, 'next' => 3] as $link => $page) {
            $url = $response->json("links.{$link}");
            $this->assertIsString($url);
            $this->assertSame('/api/orders', parse_url($url, PHP_URL_PATH));
            parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
            $this->assertSame((string) $page, $parameters['page']);
            $this->assertSame('2', $parameters['row_counts_per_page']);
        }
    }

    /**
     * 訂單列表: 最後一頁僅回傳剩餘訂單，筆數不足一頁時不應提供下一頁連結。
     */
    public function test_index_returns_the_remaining_orders_on_the_last_page(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $orders = Order::factory()->for($member)->count(3)->create([
            'created_at' => '2026-10-12 12:00:00',
        ]);

        $this->getJson('/api/orders?row_counts_per_page=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $orders[0]->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.from', 3)
            ->assertJsonPath('meta.to', 3)
            ->assertJsonPath('links.next', null);
    }

    /**
     * 訂單列表: 超出末頁時回傳空資料，但保留實際總筆數與請求頁碼供前端處理。
     */
    public function test_index_returns_an_empty_page_without_losing_total_when_page_is_out_of_range(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        Order::factory()->for($member)->count(3)->create();

        $this->getJson('/api/orders?row_counts_per_page=2&page=3')->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.from', null)
            ->assertJsonPath('meta.to', null);
    }

    /**
     * 訂單列表: 接受每頁一至三十筆的邊界值，省略或空值參數應套用預設分頁。
     */
    #[DataProvider('validOrderPaginationProvider')]
    public function test_index_accepts_pagination_boundaries_and_defaults_for_omitted_or_null_parameters(
        string $query,
        int $pageSize,
        int $page,
    ): void {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        Order::factory()->for($member)->count(31)->create();

        $this->getJson("/api/orders?{$query}")->assertOk()
            ->assertJsonCount(min($pageSize, max(0, 31 - ($page - 1) * $pageSize)), 'data')
            ->assertJsonPath('meta.current_page', $page)
            ->assertJsonPath('meta.per_page', $pageSize)
            ->assertJsonPath('meta.total', 31);
    }

    public static function validOrderPaginationProvider(): array
    {
        return [
            'minimum page size' => ['row_counts_per_page=1', 1, 1],
            'maximum page size' => ['row_counts_per_page=30', 30, 1],
            'page only' => ['page=2', 10, 2],
            'null parameters' => ['row_counts_per_page=&page=', 10, 1],
        ];
    }

    /**
     * 訂單列表: 非法分頁參數應回傳 422 與對應欄位錯誤，不可默默修正為有效值。
     */
    #[DataProvider('invalidOrderPaginationProvider')]
    public function test_index_returns_422_for_invalid_pagination_parameters(array $parameters, array $fields): void
    {
        Sanctum::actingAs(Member::factory()->create());

        $this->getJson('/api/orders?'.http_build_query($parameters))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($fields);
    }

    public static function invalidOrderPaginationProvider(): array
    {
        return [
            'zero page size' => [['row_counts_per_page' => 0], ['row_counts_per_page']],
            'negative page size' => [['row_counts_per_page' => -1], ['row_counts_per_page']],
            'page size above maximum' => [['row_counts_per_page' => 31], ['row_counts_per_page']],
            'non-numeric page size' => [['row_counts_per_page' => 'abc'], ['row_counts_per_page']],
            'fractional page size' => [['row_counts_per_page' => '1.5'], ['row_counts_per_page']],
            'array page size' => [['row_counts_per_page' => [1]], ['row_counts_per_page']],
            'zero page' => [['page' => 0], ['page']],
            'negative page' => [['page' => -1], ['page']],
            'non-numeric page' => [['page' => 'abc'], ['page']],
            'fractional page' => [['page' => '1.5'], ['page']],
            'array page' => [['page' => [1]], ['page']],
            'both invalid' => [['row_counts_per_page' => 31, 'page' => 0], ['row_counts_per_page', 'page']],
        ];
    }

    /**
     * 訂單列表: 以建立時間由新到舊排序，不可直接以 ID 取代下單時間。
     */
    public function test_index_orders_by_creation_time_descending_before_id(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $newestOrder = Order::factory()->for($member)->create(['created_at' => '2026-10-12 12:00:00']);
        $oldestOrder = Order::factory()->for($member)->create(['created_at' => '2026-10-10 12:00:00']);
        $middleOrder = Order::factory()->for($member)->create(['created_at' => '2026-10-11 12:00:00']);

        $response = $this->getJson('/api/orders');

        $response->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(
            [$newestOrder->id, $middleOrder->id, $oldestOrder->id],
            array_column($response->json('data'), 'id'),
        );
    }

    /**
     * 訂單列表: 建立時間相同時以 ID 降冪排序，確保分頁順序穩定。
     */
    public function test_index_orders_by_id_descending_when_creation_times_are_equal(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $orders = Order::factory()->for($member)->count(3)->create([
            'created_at' => '2026-10-12 12:00:00',
        ]);

        $response = $this->getJson('/api/orders');

        $response->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(
            [$orders[2]->id, $orders[1]->id, $orders[0]->id],
            array_column($response->json('data'), 'id'),
        );
    }

    /**
     * 訂單列表: 各 Enum case 的訂單皆可查詢，狀態與方式欄位須保留整數原值而非中文標籤。
     */
    #[DataProvider('orderSummaryEnumProvider')]
    public function test_index_includes_orders_with_each_enum_value_without_converting_it_to_a_label(
        string $field,
        int $value,
    ): void {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([$field => $value]);

        $this->getJson('/api/orders')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath("data.0.{$field}", $value);
    }

    public static function orderSummaryEnumProvider(): array
    {
        $cases = [];

        foreach ([
            'status' => Status::cases(),
            'payment_status' => PaymentStatus::cases(),
            'payment_method' => PaymentMethod::cases(),
            'shipping_method' => ShippingMethod::cases(),
        ] as $field => $enumCases) {
            foreach ($enumCases as $case) {
                $cases["{$field}: {$case->name}"] = [$field, $case->value];
            }
        }

        return $cases;
    }

    /**
     * 訂單列表: 商品件數須逐筆訂單加總 quantity，而非明細筆數；無明細時回傳整數零。
     */
    public function test_index_sums_item_quantities_per_order_and_returns_integer_zero_for_orders_without_details(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create(['created_at' => '2026-10-12 12:00:00']);
        OrderDetail::factory()->for($order)->create(['quantity' => 2]);
        OrderDetail::factory()->for($order)->create(['quantity' => 3]);
        $emptyOrder = Order::factory()->for($member)->create(['created_at' => '2026-10-11 12:00:00']);
        $otherOrder = Order::factory()->create();
        OrderDetail::factory()->for($otherOrder)->create(['quantity' => 9]);

        $this->getJson('/api/orders')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.item_quantity', 5)
            ->assertJsonPath('data.1.id', $emptyOrder->id)
            ->assertJsonPath('data.1.item_quantity', 0);
    }

    /**
     * 訂單列表: 十筆訂單涉及明細的 SELECT 不得超過兩次，避免計算商品件數造成 N+1。
     */
    public function test_index_does_not_query_order_details_once_per_order(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $orders = Order::factory()->for($member)->count(10)->create();

        foreach ($orders as $order) {
            OrderDetail::factory()->for($order)->create(['quantity' => 3]);
        }

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $response = $this->getJson('/api/orders');
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $response->assertOk()->assertJsonCount(10, 'data');

        foreach ($response->json('data') as $summary) {
            $this->assertSame(3, $summary['item_quantity']);
        }

        $detailQueries = array_filter($queries, fn (array $query): bool => str_starts_with(
            strtolower(ltrim($query['query'])), 'select'
        ) && str_contains(strtolower($query['query']), 'order_details'));

        $this->assertLessThanOrEqual(2, count($detailQueries), 'Order details must be aggregated or batch loaded, not queried per order.');
    }

    /**
     * 訂單列表: 查詢必須保持唯讀，不可修改訂單、明細或庫存，也不可建立付款交易與物流。
     */
    public function test_index_does_not_modify_orders_inventory_or_create_payment_transactions(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create();
        $detail = OrderDetail::factory()->for($order)->create(['quantity' => 3]);
        $variant = $detail->productVariant;
        $originalOrder = $order->fresh()->getAttributes();
        $originalDetail = $detail->fresh()->getAttributes();
        $originalVariant = $variant->getAttributes();

        $this->getJson('/api/orders')->assertOk();

        $this->assertSame($originalOrder, $order->refresh()->getAttributes());
        $this->assertSame($originalDetail, $detail->refresh()->getAttributes());
        $this->assertSame($originalVariant, $variant->refresh()->getAttributes());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_details', 1);
        $this->assertDatabaseCount('payment_transactions', 0);
        $this->assertDatabaseCount('shipments', 0);
    }

    /**
     * 訂單列表: 同一會員每分鐘可讀取六十次，第六十一次應回傳 429 與重試等待時間。
     */
    public function test_index_is_rate_limited_after_sixty_requests_per_minute(): void
    {
        $this->freezeTime();
        Sanctum::actingAs(Member::factory()->create());

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->getJson('/api/orders')->assertOk();
        }

        $this->getJson('/api/orders')->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

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
     * 建立訂單: 必須提供收件人姓名與電話，宅配另需提供收件郵遞區號與地址。
     */
    public function test_store_returns_422_when_required_recipient_information_is_missing(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_name', 'recipient_phone', 'recipient_zip_code', 'recipient_address']);
    }

    /**
     * 建立訂單: 收件人電話必須符合手機號碼格式。
     */
    public function test_store_returns_422_when_recipient_phone_format_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        foreach (['091234567', '0212345678', '09123456789', '09abcdefgh'] as $recipientPhone) {
            $response = $this->postJson('/api/orders', [
                'payment_method' => PaymentMethod::CREDIT_CARD->value,
                'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                'recipient_name' => '王小明',
                'recipient_phone' => $recipientPhone,
                'recipient_zip_code' => '100',
                'recipient_address' => '台北市信義區測試路 1 號',
            ], [
                'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['recipient_phone']);
        }
    }

    /**
     * 建立訂單: 宅配收件郵遞區號必須為 3 至 6 碼數字。
     */
    public function test_store_returns_422_when_home_delivery_recipient_zip_code_format_is_invalid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        foreach (['10', '1000000', 'ABC', '100-01'] as $recipientZipCode) {
            $response = $this->postJson('/api/orders', [
                'payment_method' => PaymentMethod::CREDIT_CARD->value,
                'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                'recipient_name' => '王小明',
                'recipient_phone' => '0912345678',
                'recipient_zip_code' => $recipientZipCode,
                'recipient_address' => '台北市信義區測試路 1 號',
            ], [
                'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['recipient_zip_code']);
        }
    }

    /**
     * 建立訂單: 宅配收件地址不可只有空白。
     */
    public function test_store_returns_422_when_home_delivery_recipient_address_is_blank(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '   ',
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_address']);
    }

    /**
     * 建立訂單: 收件人姓名與宅配地址不可超過欄位長度限制。
     */
    public function test_store_returns_422_when_recipient_information_exceeds_max_length(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_name' => str_repeat('王', 51),
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => str_repeat('台', 501),
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipient_name', 'recipient_address']);
    }

    /**
     * 建立訂單: 超商取貨不需提供收件地址。
     */
    public function test_store_allows_convenience_store_shipping_without_recipient_address(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $idempotencyKey = '01J3QS2AJMZV09DNXQ2EE4NM2C';

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')
            ->once()
            ->with($member->id, $idempotencyKey, PaymentMethod::CREDIT_CARD->value, ShippingMethod::CONVENIENCE_STORE->value, [
                'name' => '王小明',
                'phone' => '0912345678',
                'zip_code' => null,
                'address' => null,
            ], [
                'code' => 'STORE001',
                'type' => StoreType::UNIMART->value,
                'name' => '測試門市',
                'address' => '台北市信義區測試路 1 號',
            ])
            ->andReturn([
                'status' => 201,
                'message' => '訂單已建立。',
            ]);

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'store_type' => StoreType::UNIMART->value,
            'store_code' => 'STORE001',
            'store_name' => '測試門市',
            'store_address' => '台北市信義區測試路 1 號',
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('message', '訂單已建立。');
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
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
     * 建立訂單: 超商門市資訊不可超過欄位長度限制。
     */
    public function test_store_returns_422_when_store_information_exceeds_max_length(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $orderService = Mockery::mock(OrderService::class);
        $orderService->shouldReceive('storeOrder')->never();

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'store_type' => StoreType::UNIMART->value,
            'store_code' => str_repeat('S', 33),
            'store_name' => str_repeat('店', 51),
            'store_address' => str_repeat('台', 501),
        ], [
            'Idempotency-Key' => '01J3QS2AJMZV09DNXQ2EE4NM2E',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['store_code', 'store_name', 'store_address']);
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
            ->with($member->id, $idempotencyKey, PaymentMethod::CREDIT_CARD->value, ShippingMethod::HOME_DELIVERY->value, [
                'name' => '王小明',
                'phone' => '0912345678',
                'zip_code' => '100',
                'address' => '台北市信義區測試路 1 號',
            ], [
                'code' => null,
                'type' => null,
                'name' => null,
                'address' => null,
            ])
            ->andReturn([
                'status' => 201,
                'message' => '訂單已建立。',
                'data' => [
                    'id' => 1,
                    'number' => 'ORD20260823K7P4XQ',
                    'status' => 3,
                    'payment_method' => PaymentMethod::CREDIT_CARD->value,
                    'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                    'recipient_name' => '王小明',
                    'recipient_phone' => '0912345678',
                    'recipient_address' => '台北市信義區測試路 1 號',
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
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
            ->with($member->id, $idempotencyKey, PaymentMethod::CREDIT_CARD->value, ShippingMethod::HOME_DELIVERY->value, [
                'name' => '王小明',
                'phone' => '0912345678',
                'zip_code' => '100',
                'address' => '台北市信義區測試路 1 號',
            ], [
                'code' => null,
                'type' => null,
                'name' => null,
                'address' => null,
            ])
            ->andReturn([
                'status' => 200,
                'message' => '訂單已存在。',
                'data' => [
                    'id' => 1,
                    'number' => 'ORD20260823K7P4XQ',
                    'status' => 3,
                    'payment_method' => PaymentMethod::CREDIT_CARD->value,
                    'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                    'recipient_name' => '王小明',
                    'recipient_phone' => '0912345678',
                    'recipient_address' => '台北市信義區測試路 1 號',
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => null,
            'recipient_address' => null,
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
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
                'recipient_name' => '王小明',
                'recipient_phone' => '0912345678',
                'recipient_zip_code' => '100',
                'recipient_address' => '台北市信義區測試路 1 號',
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $secondResponse = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
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
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
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
            ->with($member->id, Mockery::type('string'), PaymentMethod::CREDIT_CARD->value, ShippingMethod::HOME_DELIVERY->value, [
                'name' => '王小明',
                'phone' => '0912345678',
                'zip_code' => '100',
                'address' => '台北市信義區測試路 1 號',
            ], [
                'code' => null,
                'type' => null,
                'name' => null,
                'address' => null,
            ])
            ->andReturn([
                'status' => 201,
                'message' => '訂單已建立。',
            ]);

        $this->app->instance(OrderService::class, $orderService);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/orders', [
                'payment_method' => PaymentMethod::CREDIT_CARD->value,
                'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                'recipient_name' => '王小明',
                'recipient_phone' => '0912345678',
                'recipient_zip_code' => '100',
                'recipient_address' => '台北市信義區測試路 1 號',
            ], [
                'Idempotency-Key' => sprintf('01J3QS2AJMZV09DNXQ2EE4NM%02d', $attempt),
            ])->assertStatus(201);
        }

        $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
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
            ->with($member->id, $idempotencyKey, PaymentMethod::CREDIT_CARD->value, ShippingMethod::HOME_DELIVERY->value, [
                'name' => '王小明',
                'phone' => '0912345678',
                'zip_code' => '100',
                'address' => '台北市信義區測試路 1 號',
            ], [
                'code' => null,
                'type' => null,
                'name' => null,
                'address' => null,
            ])
            ->andReturn([
                'status' => $status,
                'message' => $message,
            ]);

        $this->app->instance(OrderService::class, $orderService);

        $response = $this->postJson('/api/orders', [
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => '台北市信義區測試路 1 號',
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
