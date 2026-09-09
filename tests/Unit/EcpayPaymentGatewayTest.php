<?php

namespace Tests\Unit;

use App\Enums\Order\PaymentMethod as OrderPaymentMethod;
use App\Enums\PaymentTransaction\PaymentMethod;
use App\Gateways\Payments\EcpayPaymentGateway;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcpayPaymentGatewayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 綠界付款 Gateway: 應依付款交易與訂單明細建立前端表單提交資訊。
     */
    public function test_build_payment_request_returns_ecpay_checkout_form_payload(): void
    {
        $this->setEcpayConfig();

        $order = Order::factory()->create([
            'payment_method' => OrderPaymentMethod::CREDIT_CARD->value,
            'total_amount' => 1280,
        ]);
        $productVariant = ProductVariant::factory()->create([
            'sku' => 'BAG-BLACK-M',
            'price' => 640,
        ]);
        OrderDetail::factory()
            ->for($order)
            ->forProductVariant($productVariant, 2)
            ->create();
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'merchant_trade_no' => 'PAY202609050001',
            'amount' => 1280,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'created_at' => '2026-09-05 10:30:00',
        ]);

        $paymentRequest = app(EcpayPaymentGateway::class)->buildPaymentRequest($paymentTransaction);

        $this->assertSame('https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5', $paymentRequest['action']);
        $this->assertSame('POST', $paymentRequest['method']);
        $this->assertSame('3002599', $paymentRequest['params']['MerchantID']);
        $this->assertSame('PAY202609050001', $paymentRequest['params']['MerchantTradeNo']);
        $this->assertSame('2026/09/05 10:30:00', $paymentRequest['params']['MerchantTradeDate']);
        $this->assertSame('aio', $paymentRequest['params']['PaymentType']);
        $this->assertSame(1280, $paymentRequest['params']['TotalAmount']);
        $this->assertSame('Order payment', $paymentRequest['params']['TradeDesc']);
        $this->assertStringContainsString(' x 2', $paymentRequest['params']['ItemName']);
        $this->assertSame('http://localhost/api/payment-callbacks/ecpay', $paymentRequest['params']['ReturnURL']);
        $this->assertSame('http://localhost/orders', $paymentRequest['params']['ClientBackURL']);
        $this->assertArrayNotHasKey('PaymentInfoURL', $paymentRequest['params']);
        $this->assertArrayNotHasKey('ClientRedirectURL', $paymentRequest['params']);
        $this->assertSame('Credit', $paymentRequest['params']['ChoosePayment']);
        $this->assertSame(1, $paymentRequest['params']['EncryptType']);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $paymentRequest['params']['CheckMacValue']);
        $this->assertArrayNotHasKey('HashKey', $paymentRequest['params']);
        $this->assertArrayNotHasKey('HashIV', $paymentRequest['params']);
    }

    /**
     * 綠界付款 Gateway: 應依訂單付款方式對應綠界 ChoosePayment。
     */
    public function test_build_payment_request_resolves_ecpay_choose_payment_from_payment_method(): void
    {
        $this->setEcpayConfig();

        $cases = [
            [PaymentMethod::CREDIT_CARD, 'Credit'],
            [PaymentMethod::ATM, 'ATM'],
            [PaymentMethod::CVS, 'CVS'],
            [PaymentMethod::BARCODE, 'BARCODE'],
        ];

        foreach ($cases as [$paymentMethod, $choosePayment]) {
            $order = Order::factory()->create([
                'payment_method' => $paymentMethod->value,
            ]);
            $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
                'payment_method' => $paymentMethod->value,
            ]);

            $paymentRequest = app(EcpayPaymentGateway::class)->buildPaymentRequest($paymentTransaction);

            $this->assertSame($choosePayment, $paymentRequest['params']['ChoosePayment']);
        }
    }

    /**
     * 綠界付款 Gateway: 非即時付款方式應帶取號通知與取號結果導回網址。
     */
    public function test_build_payment_request_includes_payment_info_urls_for_non_instant_payment_methods(): void
    {
        $this->setEcpayConfig();

        $cases = [
            PaymentMethod::ATM,
            PaymentMethod::CVS,
            PaymentMethod::BARCODE,
        ];

        foreach ($cases as $paymentMethod) {
            $order = Order::factory()->create([
                'payment_method' => $paymentMethod->value,
            ]);
            $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
                'payment_method' => $paymentMethod->value,
            ]);

            $paymentRequest = app(EcpayPaymentGateway::class)->buildPaymentRequest($paymentTransaction);

            $this->assertSame('http://localhost/api/payment-callbacks/ecpay', $paymentRequest['params']['PaymentInfoURL']);
            $this->assertSame('http://localhost/orders/payment-info', $paymentRequest['params']['ClientRedirectURL']);
            $this->assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $paymentRequest['params']['CheckMacValue']);
        }
    }

    /**
     * 綠界付款 Gateway: 未設定非即時付款專用網址時，應沿用既有 callback 與返回網址。
     */
    public function test_build_payment_request_falls_back_to_existing_urls_for_non_instant_payment_methods(): void
    {
        $this->setEcpayConfig();
        config()->set('services.ecpay.payment_info_url', null);
        config()->set('services.ecpay.client_redirect_url', null);

        $order = Order::factory()->create([
            'payment_method' => OrderPaymentMethod::ATM->value,
        ]);
        $paymentTransaction = PaymentTransaction::factory()->for($order)->create([
            'payment_method' => PaymentMethod::ATM->value,
        ]);

        $paymentRequest = app(EcpayPaymentGateway::class)->buildPaymentRequest($paymentTransaction);

        $this->assertSame($paymentRequest['params']['ReturnURL'], $paymentRequest['params']['PaymentInfoURL']);
        $this->assertSame($paymentRequest['params']['ClientBackURL'], $paymentRequest['params']['ClientRedirectURL']);
    }

    /**
     * 綠界付款 Gateway: CheckMacValue 應使用綠界 SHA256 編碼規則產生固定結果。
     */
    public function test_make_check_mac_value_uses_ecpay_sha256_encoding_rules(): void
    {
        $this->setEcpayConfig();

        $checkMacValue = app(EcpayPaymentGateway::class)->makeCheckMacValue([
            'MerchantID' => '3002599',
            'MerchantTradeNo' => 'PAY202609060001',
            'MerchantTradeDate' => '2026/09/06 15:00:00',
            'PaymentType' => 'aio',
            'TotalAmount' => 1000,
            'TradeDesc' => 'Order payment',
            'ItemName' => 'Test Item x 1',
            'ReturnURL' => 'http://localhost/api/payment-callbacks/ecpay',
            'ClientBackURL' => 'http://localhost/orders',
            'ChoosePayment' => 'Credit',
            'EncryptType' => 1,
        ]);

        $this->assertSame('8962AED5933790AA25964249A21F983F510103683A70DF1F2B674BC915FA237C', $checkMacValue);
    }

    private function setEcpayConfig(): void
    {
        config()->set('services.ecpay.merchant_id', '3002599');
        config()->set('services.ecpay.hash_key', 'spPjZn66i0OhqJsQ');
        config()->set('services.ecpay.hash_iv', 'hT5OJckN45isQTTs');
        config()->set('services.ecpay.payment_action_url', 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5');
        config()->set('services.ecpay.return_url', 'http://localhost/api/payment-callbacks/ecpay');
        config()->set('services.ecpay.payment_info_url', 'http://localhost/api/payment-callbacks/ecpay');
        config()->set('services.ecpay.client_redirect_url', 'http://localhost/orders/payment-info');
        config()->set('services.ecpay.client_back_url', 'http://localhost/orders');
    }
}
