<?php

namespace Tests\Feature;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\Status as OrderStatus;
use App\Enums\PaymentTransaction\PaymentMethod as PaymentTransactionPaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status as PaymentTransactionStatus;
use App\Models\Member;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\PaymentTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class OrderPaymentTransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 建立付款交易: 未驗證的訪客無法為訂單建立付款交易。
     */
    public function test_guest_cannot_create_payment_transaction(): void
    {
        $order = Order::factory()->create();

        $response = $this->postJson("/api/orders/{$order->id}/payment-transactions");

        $response->assertUnauthorized();
    }

    /**
     * 建立付款交易: 會員無法為其他會員的訂單建立付款交易。
     */
    public function test_member_cannot_create_payment_transaction_for_another_members_order(): void
    {
        Sanctum::actingAs(Member::factory()->create());
        $order = Order::factory()->create();

        $response = $this->postJson("/api/orders/{$order->id}/payment-transactions");

        $response->assertNotFound()
            ->assertJsonPath('message', '找不到付款資料。');
    }

    /**
     * 建立付款交易: 不存在的訂單應回傳 404。
     */
    public function test_store_returns_not_found_when_order_does_not_exist(): void
    {
        Sanctum::actingAs(Member::factory()->create());

        $response = $this->postJson('/api/orders/999999/payment-transactions');

        $response->assertNotFound();
    }

    /**
     * 建立付款交易: 未付款訂單沒有既有交易時，應建立交易並回傳 checkout payload。
     */
    public function test_store_creates_payment_transaction_and_returns_checkout_payload_for_unpaid_order(): void
    {
        $this->configureEcpay();

        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = $this->createPayableOrder($member);

        $response = $this->postJson("/api/orders/{$order->id}/payment-transactions");

        $response->assertCreated()
            ->assertJsonPath('message', '付款交易已建立。')
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.order_number', $order->number)
            ->assertJsonPath('data.provider', Provider::ECPAY->value)
            ->assertJsonPath('data.provider_name', 'ecpay')
            ->assertJsonPath('data.status', PaymentTransactionStatus::PENDING->value)
            ->assertJsonPath('data.payment_method', PaymentTransactionPaymentMethod::CREDIT_CARD->value)
            ->assertJsonPath('data.amount', $order->total_amount)
            ->assertJsonPath('data.currency', 'TWD')
            ->assertJsonPath('data.checkout_payload.action', 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5')
            ->assertJsonPath('data.checkout_payload.method', 'POST')
            ->assertJsonPath('data.request_payload.MerchantID', '3002599')
            ->assertJsonPath('data.request_payload.ChoosePayment', 'Credit');

        $checkMacValue = $response->json('data.request_payload.CheckMacValue');
        $this->assertIsString($checkMacValue);
        $this->assertNotSame('', $checkMacValue);

        $paymentTransactionId = $response->json('data.payment_transaction_id');
        $paymentTransaction = PaymentTransaction::query()->findOrFail($paymentTransactionId);

        $this->assertSame($order->id, $paymentTransaction->order_id);
        $this->assertSame(Provider::ECPAY->value, $paymentTransaction->provider);
        $this->assertSame(PaymentTransactionStatus::PENDING->value, $paymentTransaction->status);
        $this->assertMatchesRegularExpression('/^PAY\d{8}[0-9A-Z]{6}$/', $paymentTransaction->merchant_trade_no);
        $this->assertSame($paymentTransaction->merchant_trade_no, $response->json('data.request_payload.MerchantTradeNo'));
    }

    /**
     * 建立付款交易: 付款失敗後應建立新的交易，並恢復訂單為未付款狀態。
     */
    public function test_store_creates_new_payment_transaction_after_payment_failed(): void
    {
        $this->configureEcpay();

        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = $this->createPayableOrder($member, [
            'payment_status' => PaymentStatus::FAILED->value,
        ]);
        $failedTransaction = PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::FAILED->value,
        ]);

        $response = $this->postJson("/api/orders/{$order->id}/payment-transactions");

        $response->assertCreated()
            ->assertJsonPath('message', '付款交易已建立。');

        $newPaymentTransaction = PaymentTransaction::query()->findOrFail($response->json('data.payment_transaction_id'));
        $this->assertNotSame($failedTransaction->id, $newPaymentTransaction->id);
        $this->assertNotSame($failedTransaction->merchant_trade_no, $newPaymentTransaction->merchant_trade_no);
        $this->assertSame(PaymentTransactionStatus::PENDING->value, $newPaymentTransaction->status);
        $this->assertSame(PaymentStatus::UNPAID->value, $order->refresh()->payment_status);
        $this->assertSame(2, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    /**
     * 建立付款交易: 已取消或已過期的付款交易不應阻擋建立新交易。
     */
    public function test_store_creates_new_payment_transaction_after_terminal_unpaid_transaction(): void
    {
        $this->configureEcpay();

        foreach ([PaymentTransactionStatus::CANCELED, PaymentTransactionStatus::EXPIRED] as $transactionStatus) {
            $member = Member::factory()->create();
            Sanctum::actingAs($member);
            $order = $this->createPayableOrder($member);
            $previousTransaction = PaymentTransaction::factory()->for($order)->create([
                'status' => $transactionStatus->value,
            ]);

            $response = $this->postJson("/api/orders/{$order->id}/payment-transactions");

            $response->assertCreated()
                ->assertJsonPath('message', '付款交易已建立。');

            $newPaymentTransaction = PaymentTransaction::query()->findOrFail($response->json('data.payment_transaction_id'));
            $this->assertNotSame($previousTransaction->id, $newPaymentTransaction->id);
            $this->assertSame(PaymentTransactionStatus::PENDING->value, $newPaymentTransaction->status);
        }
    }

    /**
     * 建立付款交易: 已有待付款或已授權的交易時不可重複建立。
     */
    public function test_store_returns_conflict_when_order_has_active_payment_transaction(): void
    {
        foreach ([
            [PaymentTransactionStatus::PENDING, PaymentStatus::UNPAID],
            [PaymentTransactionStatus::AUTHORIZED, PaymentStatus::PENDING],
        ] as [$transactionStatus, $paymentStatus]) {
            $member = Member::factory()->create();
            Sanctum::actingAs($member);
            $order = $this->createPayableOrder($member, [
                'payment_status' => $paymentStatus->value,
            ]);
            PaymentTransaction::factory()->for($order)->create([
                'status' => $transactionStatus->value,
            ]);

            $response = $this->postJson("/api/orders/{$order->id}/payment-transactions");

            $response->assertConflict()
                ->assertJsonPath('message', '已有進行中的付款交易。');
            $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
        }
    }

    /**
     * 建立付款交易: 訂單已付款、已退款或不在備貨中時不可建立交易。
     */
    public function test_store_returns_conflict_when_order_is_not_payable(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        $cases = [
            [OrderStatus::STOCKING, PaymentStatus::PAID],
            [OrderStatus::STOCKING, PaymentStatus::PENDING],
            [OrderStatus::STOCKING, PaymentStatus::REFUNDED],
            [OrderStatus::CANCELED, PaymentStatus::UNPAID],
            [OrderStatus::DELIVERING, PaymentStatus::UNPAID],
            [OrderStatus::DELIVERED, PaymentStatus::UNPAID],
            [OrderStatus::COMPLETED, PaymentStatus::UNPAID],
        ];

        foreach ($cases as [$orderStatus, $paymentStatus]) {
            $order = $this->createPayableOrder($member, [
                'status' => $orderStatus->value,
                'payment_status' => $paymentStatus->value,
            ]);

            $response = $this->postJson("/api/orders/{$order->id}/payment-transactions");

            $response->assertConflict()
                ->assertJsonPath('message', '此訂單目前無法建立付款交易。');
            $this->assertSame(0, PaymentTransaction::query()->where('order_id', $order->id)->count());
        }
    }

    /**
     * 建立付款交易: 非預期錯誤時不應洩漏內部錯誤訊息。
     */
    public function test_store_returns_generic_message_when_payment_transaction_creation_fails(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = $this->createPayableOrder($member);
        $paymentTransactionService = Mockery::mock(PaymentTransactionService::class);
        $paymentTransactionService->shouldReceive('create')
            ->once()
            ->andThrow(new \RuntimeException('sensitive provider configuration error'));
        $this->app->instance(PaymentTransactionService::class, $paymentTransactionService);

        $response = $this->postJson("/api/orders/{$order->id}/payment-transactions");

        $response->assertStatus(500)
            ->assertJsonPath('message', '付款交易建立失敗，請稍後再試。');
        $this->assertStringNotContainsString('sensitive provider configuration error', $response->getContent());
        $this->assertSame(0, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    /**
     * 建立付款交易: 重複呼叫時不可建立多筆待付款交易。
     */
    public function test_store_does_not_create_duplicate_pending_payment_transaction(): void
    {
        $this->configureEcpay();

        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = $this->createPayableOrder($member);

        $firstResponse = $this->postJson("/api/orders/{$order->id}/payment-transactions");
        $secondResponse = $this->postJson("/api/orders/{$order->id}/payment-transactions");

        $firstResponse->assertCreated();
        $secondResponse->assertConflict()
            ->assertJsonPath('message', '已有進行中的付款交易。');
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    private function configureEcpay(): void
    {
        config()->set('services.ecpay.merchant_id', '3002599');
        config()->set('services.ecpay.hash_key', 'spPjZn66i0OhqJsQ');
        config()->set('services.ecpay.hash_iv', 'hT5OJckN45isQTTs');
        config()->set('services.ecpay.payment_action_url', 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5');
        config()->set('services.ecpay.return_url', 'http://localhost/api/payment-callbacks/ecpay');
        config()->set('services.ecpay.client_back_url', 'http://localhost/orders');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPayableOrder(Member $member, array $attributes = []): Order
    {
        return Order::factory()->for($member)->create([
            'status' => OrderStatus::STOCKING->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_status' => PaymentStatus::UNPAID->value,
            ...$attributes,
        ]);
    }
}
