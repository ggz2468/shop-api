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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderPaymentCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 付款結帳資料: 未驗證的訪客無法取得付款結帳資料。
     */
    public function test_guest_cannot_show_payment_checkout(): void
    {
        $order = Order::factory()->create();

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertStatus(401);
    }

    /**
     * 付款結帳資料: 會員無法取得其他會員訂單的付款結帳資料。
     */
    public function test_member_cannot_show_payment_checkout_for_another_members_order(): void
    {
        Sanctum::actingAs(Member::factory()->create());
        $order = Order::factory()->create();

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertNotFound()
            ->assertJsonPath('message', '找不到付款資料。');
    }

    /**
     * 付款結帳資料: 查詢不存在的訂單時回傳 404。
     */
    public function test_show_returns_not_found_when_order_does_not_exist(): void
    {
        Sanctum::actingAs(Member::factory()->create());

        $response = $this->getJson('/api/orders/999999/payment-checkout');

        $response->assertNotFound();
    }

    /**
     * 付款結帳資料: 回傳此訂單最新一筆可付款交易的付款頁 payload。
     */
    public function test_show_returns_latest_pending_payment_checkout_payload(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'number' => 'ORD202609080001',
            'status' => OrderStatus::STOCKING->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'provider' => Provider::ECPAY->value,
            'status' => PaymentTransactionStatus::FAILED->value,
            'merchant_trade_no' => 'PAY202609080000',
            'amount' => 1000,
            'currency' => 'TWD',
            'payment_method' => PaymentTransactionPaymentMethod::CREDIT_CARD->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'provider' => Provider::ECPAY->value,
            'status' => PaymentTransactionStatus::PENDING->value,
            'merchant_trade_no' => 'PAY202609080000A',
            'amount' => 1080,
            'currency' => 'TWD',
            'payment_method' => PaymentTransactionPaymentMethod::CREDIT_CARD->value,
            'checkout_payload' => [
                'action' => 'https://example.test/old-payment',
                'method' => 'POST',
            ],
            'request_payload' => [
                'MerchantID' => '3002599',
                'MerchantTradeNo' => 'PAY202609080000A',
                'ChoosePayment' => 'Credit',
                'CheckMacValue' => 'OLD_CHECK_MAC_VALUE',
            ],
        ]);
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'provider' => Provider::ECPAY->value,
            'status' => PaymentTransactionStatus::PENDING->value,
            'merchant_trade_no' => 'PAY202609080001',
            'amount' => 1280,
            'currency' => 'TWD',
            'payment_method' => PaymentTransactionPaymentMethod::CREDIT_CARD->value,
            'checkout_payload' => [
                'action' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
                'method' => 'POST',
            ],
            'request_payload' => [
                'MerchantID' => '3002599',
                'MerchantTradeNo' => 'PAY202609080001',
                'ChoosePayment' => 'Credit',
                'CheckMacValue' => 'CHECK_MAC_VALUE',
            ],
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertOk()
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.order_number', 'ORD202609080001')
            ->assertJsonPath('data.payment_transaction_id', $paymentTransaction->id)
            ->assertJsonPath('data.provider', Provider::ECPAY->value)
            ->assertJsonPath('data.status', PaymentTransactionStatus::PENDING->value)
            ->assertJsonPath('data.payment_method', PaymentTransactionPaymentMethod::CREDIT_CARD->value)
            ->assertJsonPath('data.amount', 1280)
            ->assertJsonPath('data.currency', 'TWD')
            ->assertJsonPath('data.checkout_payload.method', 'POST')
            ->assertJsonPath('data.checkout_payload.action', 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5')
            ->assertJsonPath('data.request_payload.MerchantID', '3002599')
            ->assertJsonPath('data.request_payload.MerchantTradeNo', 'PAY202609080001')
            ->assertJsonPath('data.request_payload.ChoosePayment', 'Credit')
            ->assertJsonPath('data.request_payload.CheckMacValue', 'CHECK_MAC_VALUE');
    }

    /**
     * 付款結帳資料: 付款交易存在但 payload 尚未產生時回傳 202。
     */
    public function test_show_returns_accepted_when_payment_checkout_payload_is_not_ready(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'provider' => Provider::ECPAY->value,
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => null,
            'checkout_payload' => null,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertAccepted()
            ->assertJsonPath('message', '付款資料產生中，請稍後再試。')
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.payment_transaction_id', $paymentTransaction->id)
            ->assertJsonPath('data.provider', Provider::ECPAY->value)
            ->assertJsonPath('data.status', PaymentTransactionStatus::PENDING->value)
            ->assertJsonPath('data.ready', false);
    }

    /**
     * 付款結帳資料: 付款交易只缺 request payload 時回傳 202。
     */
    public function test_show_returns_accepted_when_request_payload_is_not_ready(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'provider' => Provider::ECPAY->value,
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => null,
            'checkout_payload' => ['action' => 'https://example.test/pay', 'method' => 'POST'],
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertAccepted()
            ->assertJsonPath('message', '付款資料產生中，請稍後再試。')
            ->assertJsonPath('data.payment_transaction_id', $paymentTransaction->id)
            ->assertJsonPath('data.ready', false);
    }

    /**
     * 付款結帳資料: 付款交易只缺 checkout payload 時回傳 202。
     */
    public function test_show_returns_accepted_when_checkout_payload_is_not_ready(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'provider' => Provider::ECPAY->value,
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => ['MerchantTradeNo' => 'PAY202609080004'],
            'checkout_payload' => null,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertAccepted()
            ->assertJsonPath('message', '付款資料產生中，請稍後再試。')
            ->assertJsonPath('data.payment_transaction_id', $paymentTransaction->id)
            ->assertJsonPath('data.ready', false);
    }

    /**
     * 付款結帳資料: 最新待付款交易尚未產生 payload 時，不應回退使用舊交易 payload。
     */
    public function test_show_returns_accepted_when_latest_pending_payment_transaction_is_not_ready(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => ['MerchantTradeNo' => 'PAY202609080005'],
            'checkout_payload' => ['action' => 'https://example.test/old-payment', 'method' => 'POST'],
        ]);
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'provider' => Provider::ECPAY->value,
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => null,
            'checkout_payload' => null,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertAccepted()
            ->assertJsonPath('data.payment_transaction_id', $paymentTransaction->id)
            ->assertJsonPath('data.ready', false);
    }

    /**
     * 付款結帳資料: 沒有待付款交易時回傳 404。
     */
    public function test_show_returns_not_found_when_order_has_no_pending_payment_transaction(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::FAILED->value,
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertNotFound()
            ->assertJsonPath('message', '找不到可付款的交易資料。');
    }

    /**
     * 付款結帳資料: 訂單已付款時不可取得付款結帳資料。
     */
    public function test_show_returns_conflict_when_order_is_already_paid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::STOCKING->value,
            'payment_status' => PaymentStatus::PAID->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => ['MerchantTradeNo' => 'PAY202609080002'],
            'checkout_payload' => ['action' => 'https://example.test/pay', 'method' => 'POST'],
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertStatus(409)
            ->assertJsonPath('message', '此訂單目前無法付款。');
    }

    /**
     * 付款結帳資料: 付款狀態非未付款時不可取得付款結帳資料。
     */
    public function test_show_returns_conflict_when_payment_status_is_not_unpaid(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        foreach ([
            PaymentStatus::PENDING,
            PaymentStatus::FAILED,
            PaymentStatus::REFUNDED,
        ] as $paymentStatus) {
            $order = Order::factory()->for($member)->create([
                'status' => OrderStatus::STOCKING->value,
                'payment_status' => $paymentStatus->value,
            ]);
            PaymentTransaction::factory()->for($order)->create([
                'status' => PaymentTransactionStatus::PENDING->value,
                'request_payload' => ['MerchantTradeNo' => 'PAY'.$paymentStatus->value],
                'checkout_payload' => ['action' => 'https://example.test/pay', 'method' => 'POST'],
            ]);

            $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

            $response->assertStatus(409)
                ->assertJsonPath('message', '此訂單目前無法付款。');
        }
    }

    /**
     * 付款結帳資料: 訂單已取消時不可取得付款結帳資料。
     */
    public function test_show_returns_conflict_when_order_is_canceled(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::CANCELED->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => ['MerchantTradeNo' => 'PAY202609080003'],
            'checkout_payload' => ['action' => 'https://example.test/pay', 'method' => 'POST'],
        ]);

        $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

        $response->assertStatus(409)
            ->assertJsonPath('message', '此訂單目前無法付款。');
    }

    /**
     * 付款結帳資料: 訂單狀態非備貨中時不可取得付款結帳資料。
     */
    public function test_show_returns_conflict_when_order_status_is_not_stocking(): void
    {
        $member = Member::factory()->create();
        Sanctum::actingAs($member);

        foreach ([
            OrderStatus::COMPLETED,
            OrderStatus::DELIVERING,
            OrderStatus::DELIVERED,
        ] as $orderStatus) {
            $order = Order::factory()->for($member)->create([
                'status' => $orderStatus->value,
                'payment_status' => PaymentStatus::UNPAID->value,
            ]);
            PaymentTransaction::factory()->for($order)->create([
                'status' => PaymentTransactionStatus::PENDING->value,
                'request_payload' => ['MerchantTradeNo' => 'PAY'.$orderStatus->value],
                'checkout_payload' => ['action' => 'https://example.test/pay', 'method' => 'POST'],
            ]);

            $response = $this->getJson("/api/orders/{$order->id}/payment-checkout");

            $response->assertStatus(409)
                ->assertJsonPath('message', '此訂單目前無法付款。');
        }
    }
}
