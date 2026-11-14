<?php

namespace Tests\Feature;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\Status as OrderStatus;
use App\Enums\PaymentTransaction\PaymentMethod as TransactionPaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status as TransactionStatus;
use App\Enums\Shipment\Provider as ShipmentProvider;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Gateways\Payments\EcpayPaymentGateway;
use App\Gateways\Shipments\EcpayLogisticsGateway;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\Shipment;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OrderCancellationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const string CONNECTION = 'order_cancellation_concurrency';

    private string $originalConnection;

    private ConnectionInterface $connection;

    private Member $member;

    private Order $order;

    private PaymentTransaction $payment;

    /** @var array<int, ProductVariant> */
    private array $variants = [];

    /** @var array<int, array{process: Process, input: InputStream}> */
    private array $requests = [];

    private string $token;

    private ?Member $checkoutMember = null;

    private ?Order $otherOrder = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = config('database.default');
        $this->assertSame('mysql', DB::connection()->getDriverName(), 'These tests require MySQL row locks and independent connections.');
        config()->set('database.connections.'.self::CONNECTION, config('database.connections.'.$this->originalConnection));
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);
        $this->connection = DB::connection(self::CONNECTION);
        config()->set($this->gatewayConfig());

        // RefreshDatabase's outer transaction is invisible to child processes; commit only this fixture on a separate connection.
        $this->member = Member::factory()->create();
        $this->order = Order::factory()->for($this->member)->create([
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'total_amount' => 1000,
            'recipient_zip_code' => '100',
        ]);
        $this->token = $this->member->createToken('order-cancellation-concurrency')->plainTextToken;
        $this->variants[] = ProductVariant::factory()->create(['stock_quantity' => 10]);
        $this->variants[] = ProductVariant::factory()->create(['stock_quantity' => 20]);

        // Insert details in reverse product ID order to expose inconsistent stock-lock ordering.
        foreach (array_reverse($this->variants) as $variant) {
            OrderDetail::query()->create([
                'order_id' => $this->order->id,
                'product_variant_id' => $variant->id,
                'product_name' => 'Cancellation concurrency product',
                'product_sku' => $variant->sku,
                'product_color' => 'black',
                'product_size' => 1,
                'product_price' => 250,
                'quantity' => 2,
                'subtotal' => 500,
            ]);
        }

        $this->payment = PaymentTransaction::factory()->for($this->order)->create([
            'provider' => Provider::ECPAY->value,
            'payment_method' => TransactionPaymentMethod::CREDIT_CARD->value,
            'amount' => 1000,
            'status' => TransactionStatus::PENDING->value,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->requests as $request) {
                if ($request['process']->isRunning()) {
                    $request['process']->stop();
                }

                $request['input']->close();
            }

            if (isset($this->connection)) {
                while ($this->connection->transactionLevel() > 0) {
                    $this->connection->rollBack();
                }

                $this->deleteCommittedFixture();
                $this->connection->disconnect();
            }
        } finally {
            if (isset($this->originalConnection)) {
                DB::setDefaultConnection($this->originalConnection);
            }

            parent::tearDown();
        }
    }

    /**
     * 取消訂單並行: 三個取消請求應等待同一筆訂單鎖並皆回傳 200，各商品庫存僅依固定順序回補一次。
     */
    public function test_three_concurrent_cancellations_restore_each_product_once(): void
    {
        $this->connection->beginTransaction();
        Order::query()->lockForUpdate()->findOrFail($this->order->id);
        $processes = [
            $this->startRequest('cancel'),
            $this->startRequest('cancel'),
            $this->startRequest('cancel'),
        ];

        foreach ($processes as $process) {
            $this->waitForMarker($process, 'BEFORE_ORDER_LOCK');
            $this->assertStringNotContainsString('ORDER_LOCKED', $process->getOutput());
        }

        $this->connection->commit();

        foreach ($processes as $process) {
            $result = $this->response($process);
            $this->assertSame(200, $result['status']);
            $this->assertOrderFirstLocking($result['trace']);
        }

        $this->assertCanceledState();
    }

    /**
     * 並行測試控制案例: 兩個付款重試請求應透過訂單鎖序列化，僅建立一筆新待付款交易，驗證同步機制有效。
     */
    public function test_payment_retry_control_serializes_two_real_requests_at_the_order_lock(): void
    {
        $this->order->update(['payment_status' => PaymentStatus::FAILED->value]);
        $this->payment->update(['status' => TransactionStatus::FAILED->value]);
        $first = $this->startRequest('retry', true);
        $this->waitForMarker($first, 'ORDER_LOCKED');
        $second = $this->startRequest('retry');
        $this->waitForMarker($second, 'BEFORE_ORDER_LOCK');
        $this->assertStringNotContainsString('ORDER_LOCKED', $second->getOutput());
        $this->requests[spl_object_id($first)]['input']->write("CONTINUE\n");

        $firstResult = $this->response($first);
        $secondResult = $this->response($second);

        $this->assertSame(201, $firstResult['status']);
        $this->assertSame(409, $secondResult['status']);
        $this->assertOrderFirstLocking($firstResult['trace']);
        $this->assertOrderFirstLocking($secondResult['trace']);
        $this->assertSame(2, $this->order->paymentTransactions()->count());
        $this->assertSame(1, $this->order->paymentTransactions()->where('status', TransactionStatus::PENDING->value)->count());
        $this->assertStock(false);
    }

    /**
     * 取消訂單並行: 等待訂單鎖期間若訂單付款、交易付款、授權或物流狀態改變，取得鎖後應重新檢查並回傳 409，不回補庫存。
     */
    #[DataProvider('stateChangedWhileWaitingProvider')]
    public function test_cancellation_rechecks_state_after_waiting_for_the_order_lock(string $change, int $expectedStatus): void
    {
        $this->connection->beginTransaction();
        Order::query()->lockForUpdate()->findOrFail($this->order->id);
        $process = $this->startRequest('cancel');
        $this->waitForMarker($process, 'BEFORE_ORDER_LOCK');
        $this->assertStringNotContainsString('ORDER_LOCKED', $process->getOutput());

        match ($change) {
            'paid' => $this->order->update(['payment_status' => PaymentStatus::PAID->value]),
            'transaction-paid' => $this->payment->update([
                'status' => TransactionStatus::PAID->value,
                'paid_at' => now(),
            ]),
            'authorized' => $this->payment->update(['status' => TransactionStatus::AUTHORIZED->value]),
            'shipment' => Shipment::factory()->for($this->order)->create(['status' => ShipmentStatus::PENDING->value]),
            'delivering' => $this->order->update(['status' => OrderStatus::DELIVERING->value]),
        };
        $expectedOrder = $this->order->fresh()->getAttributes();
        $expectedPayment = $this->payment->fresh()->getAttributes();
        $expectedShipments = Shipment::query()->where('order_id', $this->order->id)->get()->toArray();
        $this->connection->commit();

        $result = $this->response($process);

        $this->assertSame($expectedStatus, $result['status']);
        $this->assertOrderFirstLocking($result['trace']);
        $this->assertSame($expectedOrder, $this->order->refresh()->getAttributes());
        $this->assertSame($expectedPayment, $this->payment->refresh()->getAttributes());
        $this->assertSame($expectedShipments, Shipment::query()->where('order_id', $this->order->id)->get()->toArray());
        $this->assertStock(false);
    }

    public static function stateChangedWhileWaitingProvider(): array
    {
        return [
            'payment succeeds' => ['paid', 409],
            'transaction becomes paid while order remains unpaid' => ['transaction-paid', 409],
            'transaction becomes authorized' => ['authorized', 409],
            'shipment appears' => ['shipment', 409],
            'order starts delivering' => ['delivering', 409],
        ];
    }

    /**
     * 取消與付款回呼並行: 成功、授權及失敗回呼皆應先鎖訂單，依鎖定先後決定取消結果並維持付款、庫存與物流一致。
     */
    #[DataProvider('paymentCallbackRaceProvider')]
    public function test_cancellation_and_signed_payment_callback_are_serialized_using_the_same_order_first_lock(string $callback, bool $cancelFirst): void
    {
        if ($callback === 'authorized') {
            $this->order->update(['payment_method' => PaymentMethod::ATM->value]);
            $this->payment->update(['payment_method' => TransactionPaymentMethod::ATM->value]);
        }

        $payload = $this->signedCallbackPayload($callback);
        [$cancelResult, $callbackResult] = $this->race('callback', $cancelFirst, $payload);

        $this->assertOrderFirstLocking($cancelResult['trace']);
        $this->assertOrderFirstLocking($callbackResult['trace']);

        if ($cancelFirst) {
            $this->assertSame(200, $cancelResult['status']);
            $this->assertSame(400, $callbackResult['status']);
            $this->assertSame('0|Invalid payment transaction status', $callbackResult['body']);
            $this->assertCanceledState();

            return;
        }

        $this->assertSame(200, $callbackResult['status']);
        $this->assertSame('1|OK', $callbackResult['body']);

        if ($callback === 'failed') {
            $this->assertSame(200, $cancelResult['status']);
            $this->assertSame(OrderStatus::CANCELED->value, $this->order->refresh()->status);
            $this->assertSame(PaymentStatus::FAILED->value, $this->order->payment_status);
            $this->assertSame(TransactionStatus::FAILED->value, $this->payment->refresh()->status);
            $this->assertStock(true);
            $this->assertSame(0, $this->order->shipments()->count());

            return;
        }

        $this->assertSame(409, $cancelResult['status']);
        $this->assertSame(OrderStatus::STOCKING->value, $this->order->refresh()->status);
        $this->assertSame(
            $callback === 'paid' ? PaymentStatus::PAID->value : PaymentStatus::PENDING->value,
            $this->order->payment_status,
        );
        $this->assertSame(
            $callback === 'paid' ? TransactionStatus::PAID->value : TransactionStatus::AUTHORIZED->value,
            $this->payment->refresh()->status,
        );
        $this->assertStock(false);
        $this->assertSame($callback === 'paid' ? 1 : 0, $this->order->shipments()->count());
    }

    public static function paymentCallbackRaceProvider(): array
    {
        $cases = [];

        foreach (['paid', 'authorized', 'failed'] as $callback) {
            $cases["cancel before {$callback}"] = [$callback, true];
            $cases["{$callback} before cancel"] = [$callback, false];
        }

        return $cases;
    }

    /**
     * 取消與付款重試並行: 不論何者先取得訂單鎖，取消後皆不得留下進行中的交易，且庫存僅回補一次。
     */
    #[DataProvider('winnerProvider')]
    public function test_cancellation_and_payment_retry_do_not_leave_an_active_transaction_on_a_canceled_order(bool $cancelFirst): void
    {
        $this->order->update(['payment_status' => PaymentStatus::FAILED->value]);
        $this->payment->update(['status' => TransactionStatus::FAILED->value]);

        [$cancelResult, $retryResult] = $this->race('retry', $cancelFirst);

        $this->assertSame(200, $cancelResult['status']);
        $this->assertSame($cancelFirst ? 409 : 201, $retryResult['status']);
        $this->assertOrderFirstLocking($cancelResult['trace']);
        $this->assertOrderFirstLocking($retryResult['trace']);
        $this->assertSame(OrderStatus::CANCELED->value, $this->order->refresh()->status);
        $this->assertSame(TransactionStatus::FAILED->value, $this->payment->refresh()->status);
        $this->assertSame($cancelFirst ? 1 : 2, $this->order->paymentTransactions()->count());
        $this->assertSame(0, $this->order->paymentTransactions()->whereIn('status', [
            TransactionStatus::PENDING->value,
            TransactionStatus::AUTHORIZED->value,
        ])->count());
        $this->assertStock(true);
        $this->assertSame(0, $this->order->shipments()->count());
    }

    /**
     * 取消與首次付款交易建立並行: 兩者應共用訂單鎖，取消後不得新增交易，取消前已建立的交易則應一併取消。
     */
    #[DataProvider('winnerProvider')]
    public function test_initial_payment_creation_and_cancellation_share_the_order_lock_and_recheck_canceled_state(bool $cancelFirst): void
    {
        $this->payment->delete();

        [$cancelResult, $creationResult] = $this->race('initial-payment', $cancelFirst);

        $this->assertSame(200, $cancelResult['status']);
        $this->assertSame(200, $creationResult['status']);
        $this->assertOrderFirstLocking($cancelResult['trace']);
        $this->assertOrderFirstLocking($creationResult['trace']);
        $this->assertSame(OrderStatus::CANCELED->value, $this->order->refresh()->status);
        $this->assertSame($cancelFirst ? 0 : 1, $this->order->paymentTransactions()->count());
        $this->assertSame(0, $this->order->paymentTransactions()->where('status', '!=', TransactionStatus::CANCELED->value)->count());
        $this->assertStock(true);
        $this->assertSame(0, $this->order->shipments()->count());
    }

    public static function winnerProvider(): array
    {
        return [
            'cancellation acquires the order lock first' => [true],
            'other operation acquires the order lock first' => [false],
        ];
    }

    /**
     * 取消與結帳並行: 不同會員訂單共用兩個商品時，回補與扣庫存須依商品 ID 升冪執行，不遺失更新或死結。
     */
    #[DataProvider('winnerProvider')]
    public function test_cancellation_and_another_members_checkout_preserve_shared_inventory(bool $cancelFirst): void
    {
        $options = $this->prepareCheckout('stock');
        $first = $this->startRequest($cancelFirst ? 'cancel' : 'checkout', true, [], $options);
        $this->waitForMarker($first, 'STOCK_WRITTEN');
        $second = $this->startRequest($cancelFirst ? 'checkout' : 'cancel', false, [], $options);
        $this->waitForMarker($second, 'BEFORE_STOCK_WRITE');
        $this->assertStringNotContainsString('STOCK_WRITTEN', $second->getOutput());
        $this->requests[spl_object_id($first)]['input']->write("CONTINUE\n");
        $firstResult = $this->response($first);
        $secondResult = $this->response($second);
        [$cancelResult, $checkoutResult] = $cancelFirst ? [$firstResult, $secondResult] : [$secondResult, $firstResult];

        $this->assertSame(200, $cancelResult['status']);
        $this->assertSame(201, $checkoutResult['status']);
        $this->assertOrderFirstLocking($cancelResult['trace']);
        $this->assertStockWriteOrder($checkoutResult['trace']);
        $this->assertSame(9, $this->variants[0]->refresh()->stock_quantity);
        $this->assertSame(19, $this->variants[1]->refresh()->stock_quantity);
        $this->assertSame(OrderStatus::CANCELED->value, $this->order->refresh()->status);
        $this->assertSame(TransactionStatus::CANCELED->value, $this->payment->refresh()->status);
        $checkoutOrder = Order::query()->where('member_id', $this->checkoutMember->id)->sole();
        $this->assertSame(OrderStatus::STOCKING->value, $checkoutOrder->status);
        $this->assertSame(6, (int) $checkoutOrder->orderDetails()->sum('quantity'));
        $this->assertSame(2, $checkoutOrder->orderDetails()->count());
        $this->assertSame(1, $checkoutOrder->paymentTransactions()->count());
        $this->assertSame(0, $checkoutOrder->shipments()->count());
    }

    /**
     * 不同訂單取消並行: 共用兩個商品且明細順序相反時，各訂單只回補自己的數量，不遺失更新或死結。
     */
    #[DataProvider('winnerProvider')]
    public function test_cancellations_of_different_orders_restore_shared_inventory_without_lost_updates(bool $originalOrderFirst): void
    {
        $this->otherOrder = Order::factory()->for($this->member)->create([
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'total_amount' => 1500,
        ]);
        foreach ($this->order->orderDetails()->orderBy('product_variant_id')->get() as $detail) {
            $detail->replicate()->fill([
                'order_id' => $this->otherOrder->id,
                'quantity' => 3,
                'subtotal' => 750,
            ])->save();
        }
        $otherPayment = PaymentTransaction::factory()->for($this->otherOrder)->create([
            'status' => TransactionStatus::PENDING->value,
            'amount' => 1500,
        ]);
        $orders = $originalOrderFirst ? [$this->order, $this->otherOrder] : [$this->otherOrder, $this->order];
        $first = $this->startRequest('cancel', true, [], ['pause_at' => 'stock', 'order_id' => $orders[0]->id]);
        $this->waitForMarker($first, 'STOCK_WRITTEN');
        $second = $this->startRequest('cancel', false, [], ['order_id' => $orders[1]->id]);
        $this->waitForMarker($second, 'BEFORE_STOCK_WRITE');
        $this->assertStringContainsString('ORDER_LOCKED', $second->getOutput());
        $this->assertStringNotContainsString('STOCK_WRITTEN', $second->getOutput());
        $this->requests[spl_object_id($first)]['input']->write("CONTINUE\n");

        foreach ([$this->response($first), $this->response($second)] as $result) {
            $this->assertSame(200, $result['status']);
            $this->assertOrderFirstLocking($result['trace']);
        }

        foreach ($orders as $order) {
            $this->assertSame(OrderStatus::CANCELED->value, $order->refresh()->status);
            $this->assertSame(PaymentStatus::UNPAID->value, $order->payment_status);
            $this->assertSame(2, $order->orderDetails()->count());
            $this->assertSame(1, $order->paymentTransactions()->count());
            $this->assertSame(0, $order->shipments()->count());
        }
        $this->assertSame(TransactionStatus::CANCELED->value, $this->payment->refresh()->status);
        $this->assertSame(TransactionStatus::CANCELED->value, $otherPayment->refresh()->status);
        $this->assertSame(15, $this->variants[0]->refresh()->stock_quantity);
        $this->assertSame(25, $this->variants[1]->refresh()->stock_quantity);
    }

    /**
     * 庫存不足與取消並行: 回補提交後才開始交易內查詢可結帳；先讀到不足則 409，不預支未提交庫存或留下訂單。
     */
    #[DataProvider('insufficientCheckoutRaceProvider')]
    public function test_insufficient_checkout_and_cancellation_use_only_committed_inventory(bool $cancelCommitsBeforeRead): void
    {
        foreach ($this->variants as $variant) {
            $variant->update(['stock_quantity' => 1]);
        }
        $options = $this->prepareCheckout($cancelCommitsBeforeRead ? 'before-checkout-read' : 'stock-read');

        if ($cancelCommitsBeforeRead) {
            $cancel = $this->startRequest('cancel', true, [], ['pause_at' => 'stock']);
            $this->waitForMarker($cancel, 'STOCK_WRITTEN');
            $checkout = $this->startRequest('checkout', true, [], $options);
            $this->waitForMarker($checkout, 'BEFORE_CHECKOUT_READ');
            $this->assertStringNotContainsString('STOCK_READ_COMPLETED', $checkout->getOutput());
            $this->requests[spl_object_id($cancel)]['input']->write("CONTINUE\n");
            $cancelResult = $this->response($cancel);
            $this->requests[spl_object_id($checkout)]['input']->write("CONTINUE\n");
            $checkoutResult = $this->response($checkout);
        } else {
            $checkout = $this->startRequest('checkout', true, [], $options);
            $this->waitForMarker($checkout, 'STOCK_READ_COMPLETED');
            $cancel = $this->startRequest('cancel', true, [], ['pause_at' => 'stock']);
            $this->waitForMarker($cancel, 'STOCK_WRITTEN');
            $this->requests[spl_object_id($checkout)]['input']->write("CONTINUE\n");
            $checkoutResult = $this->response($checkout);
            $this->assertSame(409, $checkoutResult['status']);
            $this->assertSame(0, $this->checkoutMember->orders()->count());
            $this->requests[spl_object_id($cancel)]['input']->write("CONTINUE\n");
            $cancelResult = $this->response($cancel);
        }

        $this->assertSame(200, $cancelResult['status']);
        $this->assertOrderFirstLocking($cancelResult['trace']);
        $this->assertSame(OrderStatus::CANCELED->value, $this->order->refresh()->status);
        $this->assertSame(TransactionStatus::CANCELED->value, $this->payment->refresh()->status);
        $this->assertSame($cancelCommitsBeforeRead ? 201 : 409, $checkoutResult['status']);
        $this->assertSame($cancelCommitsBeforeRead ? 0 : 3, $this->variants[0]->refresh()->stock_quantity);
        $this->assertSame($cancelCommitsBeforeRead ? 0 : 3, $this->variants[1]->refresh()->stock_quantity);
        $this->assertSame($cancelCommitsBeforeRead ? 1 : 0, $this->checkoutMember->orders()->count());
        $this->assertSame($cancelCommitsBeforeRead ? 2 : 1, PaymentTransaction::query()->count());
        $this->assertSame(0, Shipment::query()->count());

        if ($cancelCommitsBeforeRead) {
            $this->assertStockWriteOrder($checkoutResult['trace']);
            $checkoutOrder = $this->checkoutMember->orders()->sole();
            $this->assertSame(OrderStatus::STOCKING->value, $checkoutOrder->status);
            $this->assertSame(2, $checkoutOrder->orderDetails()->count());
            $this->assertSame(6, (int) $checkoutOrder->orderDetails()->sum('quantity'));
            $this->assertSame(TransactionStatus::PENDING->value, $checkoutOrder->paymentTransactions()->sole()->status);
        } else {
            $this->assertSame('產品庫存不足。', $checkoutResult['body']['message']);
            $this->assertSame([], array_values(array_filter($checkoutResult['trace'], fn (array $entry): bool => $entry['kind'] === 'write')));
        }
    }

    public static function insufficientCheckoutRaceProvider(): array
    {
        return [
            'cancellation commits before checkout starts transactional reads' => [true],
            'checkout observes insufficient stock before cancellation commits' => [false],
        ];
    }

    /**
     * 取消與延遲物流建立並行: 物流先成立則取消回傳 409；取消先成立則過期的付款成功事件不得建立或送出物流。
     */
    #[DataProvider('winnerProvider')]
    public function test_cancellation_and_delayed_shipment_creation_share_the_order_lock(bool $cancelFirst): void
    {
        [$cancelResult, $shipmentResult] = $this->race('create-shipment', $cancelFirst);

        $this->assertSame($cancelFirst ? 200 : 409, $cancelResult['status']);
        $this->assertSame(200, $shipmentResult['status']);
        $this->assertOrderFirstLocking($cancelResult['trace']);
        $this->assertOrderFirstLocking($shipmentResult['trace']);

        if ($cancelFirst) {
            $this->assertSame(0, $shipmentResult['http_count']);
            $this->assertCanceledState();

            return;
        }

        $this->assertSame(1, $shipmentResult['http_count']);
        $this->assertSame(OrderStatus::STOCKING->value, $this->order->refresh()->status);
        $this->assertSame(ShipmentStatus::CREATED->value, $this->order->shipments()->sole()->status);
        $this->assertStock(false);
    }

    /**
     * 取消與待送物流工作並行: 工作等待訂單鎖時若訂單已取消，解鎖後不得送出外部請求或改寫物流紀錄。
     */
    public function test_waiting_shipment_submission_rechecks_canceled_order_before_sending(): void
    {
        $shipment = $this->createShipmentFixture(ShipmentStatus::PENDING);
        $before = $shipment->refresh()->getAttributes();
        $this->connection->beginTransaction();
        Order::query()->lockForUpdate()->findOrFail($this->order->id);
        $process = $this->startRequest('submit-shipment', false, ['shipment_id' => $shipment->id]);
        $this->waitForMarker($process, 'BEFORE_ORDER_LOCK');
        $this->markFixtureCanceled();
        $this->connection->commit();

        $result = $this->response($process);

        $this->assertSame(200, $result['status']);
        $this->assertOrderFirstLocking($result['trace']);
        $this->assertSame(0, $result['http_count']);
        $this->assertSame($before, $shipment->refresh()->getAttributes());
        $this->assertSame(OrderStatus::CANCELED->value, $this->order->refresh()->status);
        $this->assertStock(true);
    }

    /**
     * 物流先送單與取消並行: 已存在物流時取消須等待同一筆訂單鎖並回傳 409，不得回補庫存。
     */
    public function test_shipment_submission_wins_before_cancellation_without_restoring_stock(): void
    {
        $shipment = $this->createShipmentFixture(ShipmentStatus::PENDING);
        [$cancelResult, $submissionResult] = $this->race('submit-shipment', false, ['shipment_id' => $shipment->id]);

        $this->assertSame(409, $cancelResult['status']);
        $this->assertSame(200, $submissionResult['status']);
        $this->assertSame(1, $submissionResult['http_count']);
        $this->assertOrderFirstLocking($cancelResult['trace']);
        $this->assertOrderFirstLocking($submissionResult['trace']);
        $this->assertSame(ShipmentStatus::CREATED->value, $shipment->refresh()->status);
        $this->assertSame(OrderStatus::STOCKING->value, $this->order->refresh()->status);
        $this->assertStock(false);
    }

    /**
     * 取消與物流回呼並行: 已取消訂單收到配送或送達通知應確認但不復活；物流先處理則取消 409，庫存不變。
     */
    #[DataProvider('shipmentCallbackRaceProvider')]
    public function test_cancellation_and_signed_shipment_callback_preserve_order_and_inventory(string $logisticsStatus, bool $cancelFirst): void
    {
        $shipment = $this->createShipmentFixture($logisticsStatus === '300' ? ShipmentStatus::CREATED : ShipmentStatus::SHIPPED);
        $shipmentBefore = $shipment->refresh()->getAttributes();
        if ($cancelFirst) {
            // Delayed provider notifications can reference a shipment left by a previous inconsistent flow.
            $this->markFixtureCanceled();
        }
        $payload = $this->signedShipmentCallbackPayload($logisticsStatus);
        [$cancelResult, $callbackResult] = $this->race('shipment-callback', $cancelFirst, $payload);

        $this->assertSame($cancelFirst ? 200 : 409, $cancelResult['status']);
        $this->assertSame(200, $callbackResult['status']);
        $this->assertSame('1|OK', $callbackResult['body']);
        $this->assertOrderFirstLocking($cancelResult['trace']);
        $this->assertOrderFirstLocking($callbackResult['trace']);
        $this->assertSame(0, $callbackResult['http_count']);
        $this->assertStock($cancelFirst);

        if ($cancelFirst) {
            $this->assertSame(OrderStatus::CANCELED->value, $this->order->refresh()->status);
            $this->assertSame($shipmentBefore, $shipment->refresh()->getAttributes());
            $this->assertSame(TransactionStatus::CANCELED->value, $this->payment->refresh()->status);

            return;
        }

        $this->assertSame($logisticsStatus === '300' ? OrderStatus::DELIVERING->value : OrderStatus::DELIVERED->value, $this->order->refresh()->status);
        $this->assertSame($logisticsStatus === '300' ? ShipmentStatus::SHIPPED->value : ShipmentStatus::DELIVERED->value, $shipment->refresh()->status);
    }

    public static function shipmentCallbackRaceProvider(): array
    {
        return [
            'canceled before shipped callback' => ['300', true],
            'shipped callback before cancel' => ['300', false],
            'canceled before delivered callback' => ['2067', true],
            'delivered callback before cancel' => ['2067', false],
        ];
    }

    /**
     * 並行測試控制案例: 真實結帳 HTTP 流程可讀取獨立 process 的購物車、依序扣庫存並建立訂單及付款交易。
     */
    public function test_checkout_process_control_executes_the_real_order_creation_flow(): void
    {
        $process = $this->startRequest('checkout', true, [], $this->prepareCheckout('stock'));
        $this->waitForMarker($process, 'STOCK_WRITTEN');
        $this->requests[spl_object_id($process)]['input']->write("CONTINUE\n");

        $result = $this->response($process);

        $this->assertSame(201, $result['status']);
        $this->assertStockWriteOrder($result['trace']);
        $this->assertSame(7, $this->variants[0]->refresh()->stock_quantity);
        $this->assertSame(17, $this->variants[1]->refresh()->stock_quantity);
        $order = Order::query()->where('member_id', $this->checkoutMember->id)->sole();
        $this->assertSame(2, $order->orderDetails()->count());
        $this->assertSame(1, $order->paymentTransactions()->count());
    }

    /**
     * 庫存讀取閘門控制案例: 交易內查詢前或庫存讀取後能暫停真實結帳，庫存不足回傳 409 且無資料異動。
     */
    #[DataProvider('checkoutReadGateProvider')]
    public function test_insufficient_checkout_control_synchronizes_stock_reads_without_mutations(string $pauseAt, string $marker): void
    {
        foreach ($this->variants as $variant) {
            $variant->update(['stock_quantity' => 1]);
        }
        $process = $this->startRequest('checkout', true, [], $this->prepareCheckout($pauseAt));
        $this->waitForMarker($process, $marker);
        $this->requests[spl_object_id($process)]['input']->write("CONTINUE\n");
        $result = $this->response($process);

        $this->assertSame(409, $result['status']);
        $this->assertSame('產品庫存不足。', $result['body']['message']);
        $this->assertSame(0, $this->checkoutMember->orders()->count());
        $this->assertSame(1, PaymentTransaction::query()->count());
        $this->assertSame(1, $this->variants[0]->refresh()->stock_quantity);
        $this->assertSame(1, $this->variants[1]->refresh()->stock_quantity);
        $this->assertSame([], array_values(array_filter($result['trace'], fn (array $entry): bool => $entry['kind'] === 'write')));
    }

    public static function checkoutReadGateProvider(): array
    {
        return [
            'before transactional checkout reads' => ['before-checkout-read', 'BEFORE_CHECKOUT_READ'],
            'after reading stock' => ['stock-read', 'STOCK_READ_COMPLETED'],
        ];
    }

    /**
     * 並行測試控制案例: 真實物流建立 Listener、送單工作及簽章回呼可執行，外部 HTTP 僅以測試回應取代。
     */
    public function test_shipment_process_control_executes_real_workers_and_signed_callback(): void
    {
        $creation = $this->response($this->startRequest('create-shipment'));
        $this->assertSame(200, $creation['status']);
        $this->assertSame(1, $creation['http_count']);
        $shipment = $this->order->shipments()->sole();
        $this->assertSame(ShipmentStatus::CREATED->value, $shipment->status);

        $callback = $this->response($this->startRequest('shipment-callback', false, $this->signedShipmentCallbackPayload('300')));

        $this->assertSame(200, $callback['status']);
        $this->assertSame('1|OK', $callback['body']);
        $this->assertSame(OrderStatus::DELIVERING->value, $this->order->refresh()->status);
        $this->assertSame(ShipmentStatus::SHIPPED->value, $shipment->refresh()->status);
        $this->assertStock(false);
    }

    private function prepareCheckout(string $pauseAt): array
    {
        $this->checkoutMember = Member::factory()->create();

        return [
            'pause_at' => $pauseAt,
            'checkout_token' => $this->checkoutMember->createToken('cancellation-checkout')->plainTextToken,
            'cart' => array_map(fn (ProductVariant $variant): array => [
                'product_variant_id' => $variant->id,
                'quantity' => 3,
            ], array_reverse($this->variants)),
        ];
    }

    private function createShipmentFixture(ShipmentStatus $status): Shipment
    {
        return Shipment::factory()->for($this->order)->create([
            'provider' => ShipmentProvider::ECPAY_LOGISTICS->value,
            'status' => $status->value,
            'tracking_number' => '123456789',
            'request_payload' => ['MerchantTradeNo' => $this->order->number],
            'checkout_payload' => [
                'method' => 'POST',
                'action' => 'https://logistics-stage.ecpay.com.tw/Express/Create',
            ],
        ]);
    }

    private function markFixtureCanceled(): void
    {
        $this->order->update(['status' => OrderStatus::CANCELED->value]);
        $this->payment->update(['status' => TransactionStatus::CANCELED->value]);
        foreach ($this->variants as $variant) {
            $variant->increment('stock_quantity', 2);
        }
    }

    private function signedShipmentCallbackPayload(string $logisticsStatus): array
    {
        $payload = [
            'MerchantID' => '2000132',
            'MerchantTradeNo' => $this->order->number,
            'AllPayLogisticsID' => '123456789',
            'LogisticsType' => 'Home',
            'LogisticsSubType' => 'TCAT',
            'GoodsAmount' => '1000',
            'UpdateStatusDate' => '2026/11/06 01:00:00',
            'RtnCode' => '1',
            'RtnMsg' => 'OK',
            'LogisticsStatus' => $logisticsStatus,
        ];
        $payload['CheckMacValue'] = app(EcpayLogisticsGateway::class)->makeCheckMacValue($payload);

        return $payload;
    }

    private function race(string $otherAction, bool $cancelFirst, array $payload = []): array
    {
        $first = $this->startRequest($cancelFirst ? 'cancel' : $otherAction, true, $payload);
        $this->waitForMarker($first, 'ORDER_LOCKED');
        $second = $this->startRequest($cancelFirst ? $otherAction : 'cancel', false, $payload);
        $this->waitForMarker($second, 'BEFORE_ORDER_LOCK');
        $this->assertStringNotContainsString('ORDER_LOCKED', $second->getOutput(), 'The second request must not acquire the order lock before the first releases it.');
        $input = $this->requests[spl_object_id($first)]['input'];
        $input->write("CONTINUE\n");
        $input->close();

        $firstResult = $this->response($first);
        $secondResult = $this->response($second);

        return $cancelFirst ? [$firstResult, $secondResult] : [$secondResult, $firstResult];
    }

    private function startRequest(string $action, bool $pauseAfterOrderLock = false, array $payload = [], array $options = []): Process
    {
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config()->set(json_decode($argv[6], true, flags: JSON_THROW_ON_ERROR));
$options = json_decode($argv[8], true, flags: JSON_THROW_ON_ERROR);
\Illuminate\Support\Facades\Notification::fake();
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Http::fake([
    'https://logistics-stage.ecpay.com.tw/Express/Create' => \Illuminate\Support\Facades\Http::response(
        '1|RtnCode=1&RtnMsg=OK&AllPayLogisticsID=123456789&BookingNote=TEST',
        200,
    ),
]);
$trace = [];
$paused = false;
$orderLocked = false;
$pauseAt = $options['pause_at'] ?? 'order';
$gate = static function () use (&$paused, $argv): void {
    if ($argv[5] !== 'pause' || $paused) {
        return;
    }
    $paused = true;
    $read = [STDIN];
    $write = $except = [];
    if (stream_select($read, $write, $except, 10) !== 1 || trim((string) fgets(STDIN)) !== 'CONTINUE') {
        throw new \RuntimeException('Test synchronization gate was not released.');
    }
};
$classify = static function (string $sql): ?array {
    if (! preg_match('/^(?:select\b.*?\bfrom|update|insert\s+into|delete\s+from)\s+`?(orders|payment_transactions|product_variants|shipments)`?\b/is', $sql, $match)) {
        return null;
    }
    $locking = str_contains(strtolower($sql), 'for update');
    if (! $locking && ! preg_match('/^(update|insert|delete)\b/i', $sql)) {
        return null;
    }
    return ['table' => $match[1], 'kind' => $locking ? 'lock' : 'write'];
};
$connection = \Illuminate\Support\Facades\DB::connection();
$connection->statement('SET SESSION innodb_lock_wait_timeout = 10');
$connection->beforeExecuting(static function ($sql) use ($classify, &$orderLocked, $argv, $pauseAt, $gate): void {
    if ($pauseAt === 'before-checkout-read' && $argv[2] === 'checkout' && preg_match('/^select\b.*?\bfrom\s+`?(?:orders|product_variants)`?\b/is', $sql)) {
        echo "BEFORE_CHECKOUT_READ\n";
        flush();
        $gate();
    }
    $entry = $classify($sql);
    if ($entry !== null && $entry['table'] === 'orders' && $entry['kind'] === 'lock') {
        echo "BEFORE_ORDER_LOCK\n";
        flush();
    }
    if ($entry !== null && $entry['table'] === 'product_variants' && $entry['kind'] === 'write') {
        echo "BEFORE_STOCK_WRITE\n";
        flush();
    }
    if ($entry !== null && $entry['kind'] === 'write' && ! $orderLocked && $argv[2] !== 'checkout') {
        echo "UNPROTECTED_WRITE\n";
        flush();
    }
});
\Illuminate\Support\Facades\DB::listen(static function ($query) use (&$trace, &$orderLocked, $classify, $pauseAt, $gate): void {
    if (in_array($pauseAt, ['before-checkout-read', 'stock-read'], true) && preg_match('/^select\b.*?\bfrom\s+`?product_variants`?\b/is', $query->sql)) {
        echo "STOCK_READ_COMPLETED\n";
        flush();
        if ($pauseAt === 'stock-read') {
            $gate();
        }
    }
    $entry = $classify($query->sql);
    if ($entry === null) {
        return;
    }
    $entry['transaction_level'] = $query->connection->transactionLevel();
    $entry['sql'] = $query->sql;
    $entry['row_id'] = null;
    if (preg_match('/(?:`product_variants`\.)?`id`\s*=\s*\?/', $query->sql, $match, PREG_OFFSET_CAPTURE)) {
        $index = substr_count(substr($query->sql, 0, $match[0][1]), '?');
        $entry['row_id'] = (int) $query->bindings[$index];
    }
    $trace[] = $entry;
    if ($entry['table'] === 'product_variants' && $entry['kind'] === 'write') {
        echo "STOCK_WRITTEN\n";
        flush();
        if ($pauseAt === 'stock') {
            $gate();
        }
    }
    if ($entry['table'] !== 'orders' || $entry['kind'] !== 'lock') {
        return;
    }
    $orderLocked = true;
    echo "ORDER_LOCKED\n";
    flush();
    if ($pauseAt === 'order') {
        $gate();
    }
});
$payload = json_decode($argv[7], true, flags: JSON_THROW_ON_ERROR);
if (in_array($argv[2], ['initial-payment', 'create-shipment', 'submit-shipment'], true)) {
    match ($argv[2]) {
        'initial-payment' => \App\Events\OrderCreated::dispatch((int) $argv[3]),
        'create-shipment' => app(\App\Listeners\CreateShipment::class)->handle(
            new \App\Events\PaymentSucceeded(\App\Models\PaymentTransaction::where('order_id', $argv[3])->firstOrFail()->id),
        ),
        'submit-shipment' => app(\App\Listeners\SubmitShipmentRequest::class)->handle(
            new \App\Events\ShipmentRequestPayloadBuilt($payload['shipment_id']),
        ),
    };
    $status = 200;
    $body = [];
} else {
    [$method, $uri] = match ($argv[2]) {
        'cancel' => ['PATCH', '/api/orders/'.$argv[3].'/cancel'],
        'retry' => ['POST', '/api/orders/'.$argv[3].'/payment-transactions'],
        'callback' => ['POST', '/api/payment-callbacks/ecpay'],
        'shipment-callback' => ['POST', '/api/shipment-callbacks/ecpay'],
        'checkout' => ['POST', '/api/orders'],
    };
    if ($argv[2] === 'checkout') {
        $member = \Laravel\Sanctum\PersonalAccessToken::findToken($options['checkout_token'])->tokenable;
        foreach ($options['cart'] as $item) {
            if (app(\App\Stores\CartStore::class)->storeItem($member->id, $item['product_variant_id'], $item['quantity']) === false) {
                throw new \RuntimeException('Unable to prepare checkout cart.');
            }
        }
        $payload = [
            'payment_method' => \App\Enums\Order\PaymentMethod::CREDIT_CARD->value,
            'shipping_method' => \App\Enums\Order\ShippingMethod::HOME_DELIVERY->value,
            'recipient_name' => 'Checkout Member',
            'recipient_phone' => '0912345678',
            'recipient_zip_code' => '100',
            'recipient_address' => 'Test checkout address',
        ];
    }
    $request = \Illuminate\Http\Request::create($uri, $method, $payload, [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.($argv[2] === 'checkout' ? $options['checkout_token'] : $argv[4]),
        'HTTP_IDEMPOTENCY_KEY' => 'cancellation-race-checkout-'.$argv[3],
    ]);
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $body = json_decode($response->getContent(), true) ?? $response->getContent();
    $kernel->terminate($request, $response);
}
echo 'RESULT:'.json_encode(['status' => $status, 'body' => $body, 'trace' => $trace, 'http_count' => count(\Illuminate\Support\Facades\Http::recorded())], JSON_THROW_ON_ERROR)."\n";
PHP;

        $input = new InputStream;
        $process = new Process([
            PHP_BINARY,
            '-r',
            $script,
            base_path(),
            $action,
            (string) ($options['order_id'] ?? $this->order->id),
            $this->token,
            $pauseAfterOrderLock ? 'pause' : 'continue',
            json_encode($this->gatewayConfig(), JSON_THROW_ON_ERROR),
            json_encode($payload, JSON_THROW_ON_ERROR),
            json_encode($options, JSON_THROW_ON_ERROR),
        ]);
        $process->setInput($input);
        $process->setTimeout(20);
        $process->start();
        $this->requests[spl_object_id($process)] = ['process' => $process, 'input' => $input];

        return $process;
    }

    private function waitForMarker(Process $process, string $marker): void
    {
        // waitUntil only examines new output and can miss markers already buffered by Process.
        do {
            $process->checkTimeout();
            $running = $process->isRunning();
            $output = $process->getOutput();
            if (str_contains($output, $marker)
                || str_contains($output, 'RESULT:')
                || str_contains($output, 'UNPROTECTED_WRITE')
                || $process->getErrorOutput() !== ''
                || ! $running) {
                break;
            }

            usleep(1000);
        } while (true);

        $this->assertStringContainsString($marker, $process->getOutput(), "Request did not reach {$marker}; existing order flows must lock their order before related writes. Output: ".$process->getOutput().$process->getErrorOutput());
        $this->assertTrue($process->isRunning(), 'The HTTP request must still be running at the synchronization point.');
    }

    private function response(Process $process): array
    {
        $this->requests[spl_object_id($process)]['input']->close();
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame(1, preg_match('/^RESULT:(.+)$/m', $process->getOutput(), $matches), $process->getOutput());

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertOrderFirstLocking(array $trace): void
    {
        $this->assertNotEmpty($trace, 'The operation must lock its order before any related row mutation.');
        $this->assertSame('orders', $trace[0]['table']);
        $this->assertSame('lock', $trace[0]['kind']);

        foreach ($trace as $entry) {
            if ($entry['table'] !== 'shipments') {
                $this->assertGreaterThan(0, $entry['transaction_level'], 'Every order, payment and stock mutation must be inside a transaction.');
            }

            if ($entry['table'] === 'product_variants' && $entry['kind'] === 'lock' && $entry['row_id'] === null) {
                $this->assertMatchesRegularExpression('/order by\s+`?(?:product_variants`?\.)?`?id`?\s+asc/i', $entry['sql']);
            }
        }

        $this->assertStockWriteOrder($trace);
    }

    private function assertStockWriteOrder(array $trace): void
    {
        $stockWrites = array_values(array_filter($trace, fn (array $entry): bool => $entry['table'] === 'product_variants' && $entry['kind'] === 'write'));

        if ($stockWrites !== []) {
            foreach ($stockWrites as $entry) {
                $this->assertGreaterThan(0, $entry['transaction_level']);
            }
            $this->assertSame(
                array_map(fn (ProductVariant $variant): int => $variant->id, $this->variants),
                array_column($stockWrites, 'row_id'),
                'Stock must be updated once per variant in ascending product ID order.',
            );
        }
    }

    private function signedCallbackPayload(string $callback): array
    {
        $payload = [
            'MerchantID' => '3002599',
            'MerchantTradeNo' => $this->payment->merchant_trade_no,
            'RtnCode' => match ($callback) {
                'paid' => '1',
                'authorized' => '2',
                'failed' => '10100073',
            },
            'RtnMsg' => $callback === 'authorized' ? 'Get VirtualAccount Succeeded' : $callback,
            'TradeNo' => '250906150000100',
            'TradeAmt' => '1000',
            'TradeDate' => '2026/11/05 01:00:00',
            'PaymentType' => $callback === 'authorized' ? 'ATM_TAISHIN' : 'Credit_CreditCard',
        ];
        $payload['CheckMacValue'] = app(EcpayPaymentGateway::class)->makeCheckMacValue($payload);

        return $payload;
    }

    private function gatewayConfig(): array
    {
        return [
            'app.debug' => false,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'services.payment.default_provider' => Provider::ECPAY->value,
            'services.ecpay.merchant_id' => '3002599',
            'services.ecpay.hash_key' => 'spPjZn66i0OhqJsQ',
            'services.ecpay.hash_iv' => 'hT5OJckN45isQTTs',
            'services.ecpay.payment_action_url' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
            'services.ecpay.return_url' => 'http://localhost/api/payment-callbacks/ecpay',
            'services.shipment.default_provider' => ShipmentProvider::ECPAY_LOGISTICS->value,
            'services.ecpay_logistics.merchant_id' => '2000132',
            'services.ecpay_logistics.hash_key' => 'XBERn1YOvpM9nfZc',
            'services.ecpay_logistics.hash_iv' => 'h1ONHk4P4yqbl5LK',
            'services.ecpay_logistics.create_action_url' => 'https://logistics-stage.ecpay.com.tw/Express/Create',
            'services.ecpay_logistics.create_server_reply_url' => 'http://localhost/api/shipment-callbacks/ecpay',
            'services.ecpay_logistics.sender_name' => 'Shop API',
            'services.ecpay_logistics.sender_cell_phone' => '0911222333',
            'services.ecpay_logistics.sender_zip_code' => '100',
            'services.ecpay_logistics.sender_address' => 'Test sender address',
        ];
    }

    private function assertCanceledState(): void
    {
        $this->assertSame(OrderStatus::CANCELED->value, $this->order->refresh()->status);
        $this->assertSame(TransactionStatus::CANCELED->value, $this->payment->refresh()->status);
        $this->assertSame(1, $this->order->paymentTransactions()->count());
        $this->assertSame(0, $this->order->shipments()->count());
        $this->assertStock(true);
    }

    private function assertStock(bool $restored): void
    {
        $this->assertSame($restored ? 12 : 10, $this->variants[0]->refresh()->stock_quantity);
        $this->assertSame($restored ? 22 : 20, $this->variants[1]->refresh()->stock_quantity);
    }

    private function deleteCommittedFixture(): void
    {
        if ($this->checkoutMember instanceof Member) {
            $orderIds = $this->connection->table('orders')->where('member_id', $this->checkoutMember->id)->pluck('id');
            foreach (['shipments', 'payment_transactions', 'order_details'] as $table) {
                $this->connection->table($table)->whereIn('order_id', $orderIds)->delete();
            }
            $this->connection->table('orders')->whereIn('id', $orderIds)->delete();
            $this->connection->table('personal_access_tokens')->where('tokenable_type', Member::class)->where('tokenable_id', $this->checkoutMember->id)->delete();
            $this->connection->table('members')->where('id', $this->checkoutMember->id)->delete();
        }
        if (isset($this->order)) {
            foreach (['shipments', 'payment_transactions', 'order_details'] as $table) {
                $this->connection->table($table)->whereIn('order_id', array_filter([$this->order->id, $this->otherOrder?->id]))->delete();
            }

            $this->connection->table('orders')->whereIn('id', array_filter([$this->order->id, $this->otherOrder?->id]))->delete();
        }

        foreach ($this->variants as $variant) {
            $this->connection->table('product_variants')->where('id', $variant->id)->delete();
            $this->connection->table('products')->where('id', $variant->product_id)->delete();
            $this->connection->table('product_specs')->where('id', $variant->product_spec_id)->delete();
        }

        if (isset($this->member)) {
            $this->connection->table('personal_access_tokens')->where('tokenable_type', Member::class)->where('tokenable_id', $this->member->id)->delete();
            $this->connection->table('members')->where('id', $this->member->id)->delete();
        }
    }
}
