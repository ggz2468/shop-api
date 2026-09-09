<?php

namespace Tests\Unit;

use App\Enums\Order\PaymentStatus;
use App\Enums\Order\Status as OrderStatus;
use App\Enums\PaymentTransaction\PaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status as PaymentTransactionStatus;
use App\Models\Member;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\OrderPaymentCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPaymentCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 付款結帳資料: 非訂單所屬會員不可取得付款結帳資料。
     */
    public function test_show_returns_not_found_when_order_does_not_belong_to_member(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->create();
        PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => ['MerchantTradeNo' => 'PAY202609080001'],
            'checkout_payload' => ['action' => 'https://example.test/pay', 'method' => 'POST'],
        ]);

        $result = app(OrderPaymentCheckoutService::class)->show($member->id, $order);

        $this->assertSame(404, $result['status']);
        $this->assertSame('找不到付款資料。', $result['message']);
    }

    /**
     * 付款結帳資料: 回傳訂單所屬會員最新一筆可付款交易的付款頁 payload。
     */
    public function test_show_returns_latest_pending_payment_checkout_payload_for_order_member(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->for($member)->create([
            'number' => 'ORD202609080001',
            'status' => OrderStatus::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::PENDING->value,
            'merchant_trade_no' => 'PAY202609080000',
            'amount' => 1080,
            'request_payload' => ['MerchantTradeNo' => 'PAY202609080000'],
            'checkout_payload' => ['action' => 'https://example.test/old-payment', 'method' => 'POST'],
        ]);
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'provider' => Provider::ECPAY->value,
            'status' => PaymentTransactionStatus::PENDING->value,
            'merchant_trade_no' => 'PAY202609080001',
            'amount' => 1280,
            'currency' => 'TWD',
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'request_payload' => [
                'MerchantID' => '3002599',
                'MerchantTradeNo' => 'PAY202609080001',
                'CheckMacValue' => 'CHECK_MAC_VALUE',
            ],
            'checkout_payload' => [
                'action' => 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5',
                'method' => 'POST',
            ],
        ]);

        $result = app(OrderPaymentCheckoutService::class)->show($member->id, $order);

        $this->assertSame(200, $result['status']);
        $this->assertSame($paymentTransaction->id, $result['data']['payment_transaction_id']);
        $this->assertSame('PAY202609080001', $result['data']['request_payload']['MerchantTradeNo']);
        $this->assertSame('https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5', $result['data']['checkout_payload']['action']);
    }

    /**
     * 付款結帳資料: 最新待付款交易尚未產生 payload 時，不應回退使用舊交易 payload。
     */
    public function test_show_returns_accepted_when_latest_pending_payment_transaction_is_not_ready(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::STOCKING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => ['MerchantTradeNo' => 'PAY202609080000'],
            'checkout_payload' => ['action' => 'https://example.test/old-payment', 'method' => 'POST'],
        ]);
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => null,
            'checkout_payload' => null,
        ]);

        $result = app(OrderPaymentCheckoutService::class)->show($member->id, $order);

        $this->assertSame(202, $result['status']);
        $this->assertSame($paymentTransaction->id, $result['data']['payment_transaction_id']);
        $this->assertFalse($result['data']['ready']);
    }

    /**
     * 付款結帳資料: 訂單狀態非備貨中時不可取得付款結帳資料。
     */
    public function test_show_returns_conflict_when_order_status_is_not_stocking(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->for($member)->create([
            'status' => OrderStatus::DELIVERING->value,
            'payment_status' => PaymentStatus::UNPAID->value,
        ]);
        PaymentTransaction::factory()->for($order)->create([
            'status' => PaymentTransactionStatus::PENDING->value,
            'request_payload' => ['MerchantTradeNo' => 'PAY202609080002'],
            'checkout_payload' => ['action' => 'https://example.test/pay', 'method' => 'POST'],
        ]);

        $result = app(OrderPaymentCheckoutService::class)->show($member->id, $order);

        $this->assertSame(409, $result['status']);
        $this->assertSame('此訂單目前無法付款。', $result['message']);
    }
}
