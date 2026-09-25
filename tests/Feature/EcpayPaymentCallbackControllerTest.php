<?php

namespace Tests\Feature;

use App\Enums\Order\PaymentMethod as OrderPaymentMethod;
use App\Enums\Order\PaymentStatus;
use App\Enums\PaymentTransaction\PaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status;
use App\Gateways\Payments\EcpayPaymentGateway;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\EcpayPaymentCallbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class EcpayPaymentCallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 綠界付款回呼：真實成功 callback 應更新付款交易與訂單付款狀態。
     */
    public function test_callback_processes_real_success_payload_and_updates_payment_state(): void
    {
        Notification::fake();
        $this->fakeSuccessfulShipmentRequest();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL01',
            'amount' => 1000,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => ' PAY20260906REAL01 ',
            'TradeNo' => ' 250906150000101 ',
            'TradeAmt' => ' 1000 ',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $paymentTransaction->refresh();
        $this->assertSame(Status::PAID->value, $paymentTransaction->status);
        $this->assertSame('250906150000101', $paymentTransaction->provider_transaction_id);
        $this->assertSame('PAY20260906REAL01', $paymentTransaction->response_payload['MerchantTradeNo']);
        $this->assertSame('250906150000101', $paymentTransaction->response_payload['TradeNo']);
        $this->assertSame(PaymentStatus::PAID->value, $paymentTransaction->order->refresh()->payment_status);
    }

    /**
     * 綠界付款回呼：非信用卡的綠界付款交易也應可依成功 callback 更新付款狀態。
     */
    public function test_callback_processes_real_success_payload_for_non_credit_card_ecpay_payment(): void
    {
        Notification::fake();
        $this->fakeSuccessfulShipmentRequest();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL05',
            'amount' => 1000,
            'payment_method' => PaymentMethod::ATM->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL05',
            'PaymentType' => 'ATM_TAISHIN',
            'TradeNo' => '250906150000105',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $paymentTransaction->refresh();
        $this->assertSame(Status::PAID->value, $paymentTransaction->status);
        $this->assertSame('250906150000105', $paymentTransaction->provider_transaction_id);
        $this->assertSame('ATM_TAISHIN', $paymentTransaction->response_payload['PaymentType']);
        $this->assertSame(PaymentStatus::PAID->value, $paymentTransaction->order->refresh()->payment_status);
    }

    /**
     * 綠界付款回呼：非即時付款取號成功時應標記為付款處理中，而非付款失敗。
     */
    public function test_callback_marks_non_instant_payment_as_pending_when_authorized(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL06',
            'amount' => 1000,
            'payment_method' => PaymentMethod::ATM->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL06',
            'RtnCode' => '2',
            'RtnMsg' => 'Get VirtualAccount Succeeded',
            'PaymentType' => 'ATM_TAISHIN',
            'TradeNo' => '250906150000106',
            'PaymentDate' => '',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $paymentTransaction->refresh();
        $this->assertSame(Status::AUTHORIZED->value, $paymentTransaction->status);
        $this->assertSame('250906150000106', $paymentTransaction->provider_transaction_id);
        $this->assertSame('Get VirtualAccount Succeeded', $paymentTransaction->response_payload['RtnMsg']);
        $this->assertSame(PaymentStatus::PENDING->value, $paymentTransaction->order->refresh()->payment_status);
    }

    /**
     * 綠界付款回呼：真實失敗 callback 應更新付款交易與訂單付款狀態。
     */
    public function test_callback_processes_real_failed_payload_and_updates_payment_state(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL02',
            'amount' => 1000,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL02',
            'RtnCode' => '10100073',
            'RtnMsg' => 'Paid failed',
            'TradeNo' => '250906150000102',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $paymentTransaction->refresh();
        $this->assertSame(Status::FAILED->value, $paymentTransaction->status);
        $this->assertSame('250906150000102', $paymentTransaction->provider_transaction_id);
        $this->assertSame('Paid failed', $paymentTransaction->response_payload['reason']);
        $this->assertSame(PaymentStatus::FAILED->value, $paymentTransaction->order->refresh()->payment_status);
    }

    /**
     * 綠界付款回呼：驗簽失敗時不應更新付款交易。
     */
    public function test_callback_rejects_invalid_check_mac_value_without_updating_payment_state(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL03',
            'amount' => 1000,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL03',
            'CheckMacValue' => str_repeat('A', 64),
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Invalid CheckMacValue');

        $paymentTransaction->refresh();
        $this->assertSame(Status::PENDING->value, $paymentTransaction->status);
        $this->assertNull($paymentTransaction->response_payload);
        $this->assertSame(PaymentStatus::UNPAID->value, $paymentTransaction->order->refresh()->payment_status);
    }

    /**
     * 綠界付款回呼：已付款交易若收到金額不符的重送通知，不應直接回覆成功。
     */
    public function test_callback_rejects_paid_duplicate_when_payload_does_not_match_transaction(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL04',
            'amount' => 1000,
            'status' => Status::PAID->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL04',
            'TradeAmt' => '999',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Payment amount mismatch');
    }

    /**
     * 綠界付款回呼：找不到付款交易時應拒絕 callback。
     */
    public function test_callback_rejects_unknown_payment_transaction(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906UNKNOWN',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(404)
            ->assertSeeText('0|Payment transaction not found');
    }

    /**
     * 綠界付款回呼：缺少必要欄位時應拒絕 callback。
     */
    public function test_callback_rejects_missing_required_field(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906MISSING',
        ]);
        unset($payload['TradeNo']);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Missing required field: TradeNo');
    }

    /**
     * 綠界付款回呼：MerchantID 不符時應拒絕 callback。
     */
    public function test_callback_rejects_invalid_merchant_id_without_updating_payment_state(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL11',
            'amount' => 1000,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantID' => '9999999',
            'MerchantTradeNo' => 'PAY20260906REAL11',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Invalid MerchantID');

        $paymentTransaction->refresh();
        $this->assertSame(Status::PENDING->value, $paymentTransaction->status);
        $this->assertNull($paymentTransaction->response_payload);
    }

    /**
     * 綠界付款回呼：TradeAmt 不是純數字時應拒絕 callback。
     */
    public function test_callback_rejects_invalid_trade_amount_format_without_updating_payment_state(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL12',
            'amount' => 1000,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL12',
            'TradeAmt' => '1000.00',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Invalid TradeAmt');

        $paymentTransaction->refresh();
        $this->assertSame(Status::PENDING->value, $paymentTransaction->status);
        $this->assertNull($paymentTransaction->response_payload);
    }

    /**
     * 綠界付款回呼：已授權交易收到實際付款成功 callback 時應轉為已付款。
     */
    public function test_callback_processes_success_payload_after_non_instant_payment_was_authorized(): void
    {
        Notification::fake();
        $this->fakeSuccessfulShipmentRequest();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL07',
            'amount' => 1000,
            'payment_method' => PaymentMethod::ATM->value,
            'status' => Status::AUTHORIZED->value,
            'provider_transaction_id' => '250906150000107',
            'response_payload' => [
                'RtnCode' => '2',
                'PaymentType' => 'ATM_TAISHIN',
                'TradeNo' => '250906150000107',
            ],
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL07',
            'RtnCode' => '1',
            'RtnMsg' => 'Succeeded',
            'PaymentType' => 'ATM_TAISHIN',
            'TradeNo' => '250906150000107',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $paymentTransaction->refresh();
        $this->assertSame(Status::PAID->value, $paymentTransaction->status);
        $this->assertSame(PaymentStatus::PAID->value, $paymentTransaction->order->refresh()->payment_status);
        $this->assertSame('1', $paymentTransaction->response_payload['RtnCode']);
    }

    /**
     * 綠界付款回呼：已付款交易收到成功重送通知時應直接確認。
     */
    public function test_callback_acknowledges_paid_duplicate_without_reprocessing(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $originalPayload = [
            'RtnCode' => '1',
            'TradeNo' => '250906150000113',
        ];
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL13',
            'amount' => 1000,
            'status' => Status::PAID->value,
            'provider_transaction_id' => '250906150000113',
            'response_payload' => $originalPayload,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL13',
            'RtnCode' => '1',
            'TradeNo' => '250906150000113',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $paymentTransaction->refresh();
        $this->assertSame(Status::PAID->value, $paymentTransaction->status);
        $this->assertEquals($originalPayload, $paymentTransaction->response_payload);
    }

    /**
     * 綠界付款回呼：已授權交易收到取號成功重送通知時應直接確認，不重複改寫狀態。
     */
    public function test_callback_acknowledges_authorized_duplicate_without_reprocessing(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $originalPayload = [
            'RtnCode' => '2',
            'PaymentType' => 'ATM_TAISHIN',
            'TradeNo' => '250906150000108',
        ];
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL08',
            'amount' => 1000,
            'payment_method' => PaymentMethod::ATM->value,
            'status' => Status::AUTHORIZED->value,
            'provider_transaction_id' => '250906150000108',
            'response_payload' => $originalPayload,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL08',
            'RtnCode' => '2',
            'RtnMsg' => 'Get VirtualAccount Succeeded Again',
            'PaymentType' => 'ATM_TAISHIN',
            'TradeNo' => '250906150000108',
            'PaymentDate' => '',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $paymentTransaction->refresh();
        $this->assertSame(Status::AUTHORIZED->value, $paymentTransaction->status);
        $this->assertEquals($originalPayload, $paymentTransaction->response_payload);
        $this->assertSame(PaymentStatus::UNPAID->value, $paymentTransaction->order->refresh()->payment_status);
    }

    /**
     * 綠界付款回呼：已授權交易收到失敗 callback 時應視為狀態衝突。
     */
    public function test_callback_rejects_failed_payload_after_non_instant_payment_was_authorized(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL09',
            'amount' => 1000,
            'payment_method' => PaymentMethod::ATM->value,
            'status' => Status::AUTHORIZED->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL09',
            'RtnCode' => '10100073',
            'RtnMsg' => 'Paid failed',
            'PaymentType' => 'ATM_TAISHIN',
            'TradeNo' => '250906150000109',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Payment callback status conflict');

        $paymentTransaction->refresh();
        $this->assertSame(Status::AUTHORIZED->value, $paymentTransaction->status);
        $this->assertSame(PaymentStatus::UNPAID->value, $paymentTransaction->order->refresh()->payment_status);
    }

    /**
     * 綠界付款回呼：已失敗交易收到失敗重送通知時應直接確認。
     */
    public function test_callback_acknowledges_failed_duplicate_without_reprocessing(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $originalPayload = [
            'RtnCode' => '10100073',
            'reason' => 'Paid failed',
        ];
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL10',
            'amount' => 1000,
            'status' => Status::FAILED->value,
            'response_payload' => $originalPayload,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL10',
            'RtnCode' => '10100073',
            'RtnMsg' => 'Paid failed again',
            'TradeNo' => '250906150000110',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $paymentTransaction->refresh();
        $this->assertSame(Status::FAILED->value, $paymentTransaction->status);
        $this->assertEquals($originalPayload, $paymentTransaction->response_payload);
    }

    /**
     * 綠界付款回呼：已失敗交易收到成功 callback 時應視為狀態衝突。
     */
    public function test_callback_rejects_success_payload_after_payment_was_failed(): void
    {
        Notification::fake();
        $this->setEcpayConfig();
        $paymentTransaction = $this->createEcpayPaymentTransaction([
            'merchant_trade_no' => 'PAY20260906REAL14',
            'amount' => 1000,
            'status' => Status::FAILED->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'PAY20260906REAL14',
            'RtnCode' => '1',
            'RtnMsg' => 'Succeeded',
            'TradeNo' => '250906150000114',
        ]);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Payment callback status conflict');

        $paymentTransaction->refresh();
        $this->assertSame(Status::FAILED->value, $paymentTransaction->status);
        $this->assertNull($paymentTransaction->response_payload);
    }

    /**
     * 綠界付款回呼：成功處理時應回傳綠界要求的純文字成功內容。
     */
    public function test_callback_returns_ecpay_success_response_when_service_accepts_payload(): void
    {
        $payload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'PAY202609060001',
            'RtnCode' => '1',
            'RtnMsg' => 'Succeeded',
        ]);

        $service = Mockery::mock(EcpayPaymentCallbackService::class);
        $service->shouldReceive('handle')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 200,
                'content' => '1|OK',
            ]);

        $this->app->instance(EcpayPaymentCallbackService::class, $service);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('1|OK');
    }

    /**
     * 綠界付款回呼：付款失敗通知仍應交由 service 判斷並回傳處理結果。
     */
    public function test_callback_passes_failed_payment_payload_to_service(): void
    {
        $payload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'PAY202609060002',
            'RtnCode' => '10100073',
            'RtnMsg' => 'Paid failed',
        ]);

        $service = Mockery::mock(EcpayPaymentCallbackService::class);
        $service->shouldReceive('handle')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 200,
                'content' => '1|OK',
            ]);

        $this->app->instance(EcpayPaymentCallbackService::class, $service);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');
    }

    /**
     * 綠界付款回呼：驗簽或資料比對失敗時應原樣回傳 service 的拒絕結果。
     */
    public function test_callback_propagates_service_rejection_response(): void
    {
        $payload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'PAY202609060003',
            'CheckMacValue' => 'INVALID_CHECK_MAC_VALUE',
        ]);

        $service = Mockery::mock(EcpayPaymentCallbackService::class);
        $service->shouldReceive('handle')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 400,
                'content' => '0|Invalid CheckMacValue',
            ]);

        $this->app->instance(EcpayPaymentCallbackService::class, $service);

        $response = $this->post('/api/payment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('0|Invalid CheckMacValue');
    }

    /**
     * 綠界付款回呼：同一筆特店交易編號會套用 RateLimiter。
     */
    public function test_callback_is_rate_limited_by_merchant_trade_no(): void
    {
        $payload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'PAY202609060004',
        ]);

        $service = Mockery::mock(EcpayPaymentCallbackService::class);
        $service->shouldReceive('handle')
            ->times(30)
            ->with($payload)
            ->andReturn([
                'status' => 200,
                'content' => '1|OK',
            ]);

        $this->app->instance(EcpayPaymentCallbackService::class, $service);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/api/payment-callbacks/ecpay', $payload)->assertOk();
        }

        $this->post('/api/payment-callbacks/ecpay', $payload)->assertStatus(429);
    }

    /**
     * 綠界付款回呼：不同特店交易編號不應互相消耗交易層級的限制。
     */
    public function test_callback_rate_limit_uses_separate_bucket_for_each_merchant_trade_no(): void
    {
        $firstPayload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'PAY202609060005',
        ]);
        $secondPayload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'PAY202609060006',
        ]);

        $service = Mockery::mock(EcpayPaymentCallbackService::class);
        $service->shouldReceive('handle')
            ->times(30)
            ->with($firstPayload)
            ->andReturn([
                'status' => 200,
                'content' => '1|OK',
            ]);
        $service->shouldReceive('handle')
            ->once()
            ->with($secondPayload)
            ->andReturn([
                'status' => 200,
                'content' => '1|OK',
            ]);

        $this->app->instance(EcpayPaymentCallbackService::class, $service);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/api/payment-callbacks/ecpay', $firstPayload)->assertOk();
        }

        $this->post('/api/payment-callbacks/ecpay', $secondPayload)->assertOk();
    }

    /**
     * 綠界付款回呼：缺少特店交易編號時會退回以 IP 套用 RateLimiter。
     */
    public function test_callback_without_merchant_trade_no_is_rate_limited_by_ip(): void
    {
        $payload = $this->validCallbackPayload([
            'MerchantTradeNo' => '',
        ]);

        $service = Mockery::mock(EcpayPaymentCallbackService::class);
        $service->shouldReceive('handle')
            ->times(30)
            ->with($payload)
            ->andReturn([
                'status' => 400,
                'content' => '0|Missing MerchantTradeNo',
            ]);

        $this->app->instance(EcpayPaymentCallbackService::class, $service);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/api/payment-callbacks/ecpay', $payload)->assertStatus(400);
        }

        $this->post('/api/payment-callbacks/ecpay', $payload)->assertStatus(429);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validCallbackPayload(array $overrides = []): array
    {
        return array_merge([
            'MerchantID' => '2000132',
            'MerchantTradeNo' => 'PAY202609060000',
            'StoreID' => '',
            'RtnCode' => '1',
            'RtnMsg' => 'Succeeded',
            'TradeNo' => '250906150000001',
            'TradeAmt' => '1000',
            'PaymentDate' => '2026/09/06 15:00:00',
            'PaymentType' => 'Credit_CreditCard',
            'PaymentTypeChargeFee' => '20',
            'TradeDate' => '2026/09/06 14:58:00',
            'SimulatePaid' => '0',
            'CheckMacValue' => 'VALID_CHECK_MAC_VALUE',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEcpayPaymentTransaction(array $attributes = []): PaymentTransaction
    {
        $paymentMethod = $attributes['payment_method'] ?? PaymentMethod::CREDIT_CARD->value;
        $order = Order::factory()->create([
            'payment_method' => OrderPaymentMethod::from($paymentMethod)->value,
            'payment_status' => PaymentStatus::UNPAID->value,
            'total_amount' => $attributes['amount'] ?? 1000,
        ]);

        return PaymentTransaction::factory()->for($order)->create(array_merge([
            'provider' => Provider::ECPAY->value,
            'provider_transaction_id' => null,
            'merchant_trade_no' => 'PAY20260906REAL00',
            'amount' => $order->total_amount,
            'status' => Status::PENDING->value,
            'payment_method' => PaymentMethod::CREDIT_CARD->value,
            'response_payload' => null,
        ], $attributes));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedCallbackPayload(array $overrides = []): array
    {
        $payload = array_merge([
            'MerchantID' => '3002599',
            'MerchantTradeNo' => 'PAY20260906REAL00',
            'StoreID' => '',
            'RtnCode' => '1',
            'RtnMsg' => 'Succeeded',
            'TradeNo' => '250906150000100',
            'TradeAmt' => '1000',
            'PaymentDate' => '2026/09/06 15:00:00',
            'PaymentType' => 'Credit_CreditCard',
            'PaymentTypeChargeFee' => '20',
            'TradeDate' => '2026/09/06 14:58:00',
            'SimulatePaid' => '0',
        ], $overrides);

        $payloadForSignature = array_map(
            fn (string $value): string => trim($value),
            $payload,
        );
        $payload['CheckMacValue'] = app(EcpayPaymentGateway::class)->makeCheckMacValue($payloadForSignature);

        if (array_key_exists('CheckMacValue', $overrides)) {
            $payload['CheckMacValue'] = $overrides['CheckMacValue'];
        }

        return $payload;
    }

    private function setEcpayConfig(): void
    {
        config()->set('services.ecpay.merchant_id', '3002599');
        config()->set('services.ecpay.hash_key', 'spPjZn66i0OhqJsQ');
        config()->set('services.ecpay.hash_iv', 'hT5OJckN45isQTTs');
    }

    private function fakeSuccessfulShipmentRequest(): void
    {
        Http::fake([
            'https://logistics-stage.ecpay.com.tw/Express/Create' => Http::response(
                '1|RtnCode=1&RtnMsg=OK&AllPayLogisticsID=123456789&BookingNote=ABC123',
                200,
                ['Content-Type' => 'text/plain'],
            ),
        ]);
    }
}
