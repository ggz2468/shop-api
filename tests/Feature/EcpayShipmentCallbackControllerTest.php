<?php

namespace Tests\Feature;

use App\Enums\Order\Status as OrderStatus;
use App\Enums\Shipment\Provider;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Gateways\Shipments\EcpayLogisticsGateway;
use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class EcpayShipmentCallbackControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_CLASS = 'App\\Services\\EcpayShipmentCallbackService';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 綠界物流回呼：配送中 callback 應更新物流單與訂單配送狀態。
     */
    public function test_callback_processes_shipped_payload_and_updates_shipment_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:00:00'));
        $this->setEcpayLogisticsConfig();
        $shipment = $this->createEcpayShipment([
            'tracking_number' => '123456789',
            'status' => ShipmentStatus::CREATED->value,
        ], [
            'number' => 'ORD202609220001',
            'status' => OrderStatus::STOCKING->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => ' ORD202609220001 ',
            'AllPayLogisticsID' => ' 123456789 ',
            'LogisticsStatus' => '300',
            'LogisticsStatusName' => '配送中',
            'UpdateStatusDate' => '2026/09/22 09:58:00',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('1|OK');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::SHIPPED->value, $shipment->status);
        $this->assertNotNull($shipment->shipped_at);
        $this->assertSame('ORD202609220001', $shipment->response_payload['MerchantTradeNo']);
        $this->assertSame('123456789', $shipment->response_payload['AllPayLogisticsID']);
        $this->assertSame('300', $shipment->response_payload['LogisticsStatus']);
        $this->assertArrayNotHasKey('CheckMacValue', $shipment->response_payload);
        $this->assertSame(OrderStatus::DELIVERING->value, $shipment->order->refresh()->status);
    }

    /**
     * 綠界物流回呼：已送達 callback 應更新物流單與訂單完成狀態。
     */
    public function test_callback_processes_delivered_payload_and_updates_shipment_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 10:30:00'));
        $this->setEcpayLogisticsConfig();
        $shipment = $this->createEcpayShipment([
            'tracking_number' => '123456790',
            'status' => ShipmentStatus::SHIPPED->value,
            'shipped_at' => Carbon::parse('2026-09-22 09:00:00'),
        ], [
            'number' => 'ORD202609220002',
            'status' => OrderStatus::DELIVERING->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'ORD202609220002',
            'AllPayLogisticsID' => '123456790',
            'LogisticsStatus' => '2067',
            'LogisticsStatusName' => '已送達',
            'UpdateStatusDate' => '2026/09/22 10:25:00',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::DELIVERED->value, $shipment->status);
        $this->assertNotNull($shipment->delivered_at);
        $this->assertSame('2067', $shipment->response_payload['LogisticsStatus']);
        $this->assertSame(OrderStatus::DELIVERED->value, $shipment->order->refresh()->status);
    }

    /**
     * 綠界物流回呼：物流失敗狀態沒有貨態轉移時應確認但不更新物流單。
     */
    public function test_callback_acknowledges_failed_payload_without_updating_shipment_state(): void
    {
        $this->setEcpayLogisticsConfig();
        $shipment = $this->createEcpayShipment([
            'tracking_number' => '123456791',
            'status' => ShipmentStatus::CREATED->value,
        ], [
            'number' => 'ORD202609220003',
            'status' => OrderStatus::STOCKING->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'ORD202609220003',
            'AllPayLogisticsID' => '123456791',
            'LogisticsStatus' => '2001',
            'LogisticsStatusName' => '配送異常',
            'RtnMsg' => 'Shipment failed',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::CREATED->value, $shipment->status);
        $this->assertNull($shipment->response_payload);
        $this->assertSame(OrderStatus::STOCKING->value, $shipment->order->refresh()->status);
    }

    /**
     * 綠界物流回呼：同一狀態重送時應直接確認且不重複更新已完成資料。
     */
    public function test_callback_acknowledges_delivered_duplicate_without_reprocessing(): void
    {
        $this->setEcpayLogisticsConfig();
        $originalPayload = [
            'MerchantTradeNo' => 'ORD202609220004',
            'AllPayLogisticsID' => '123456792',
            'LogisticsStatus' => '2067',
        ];
        $deliveredAt = Carbon::parse('2026-09-22 10:00:00');
        $shipment = $this->createEcpayShipment([
            'tracking_number' => '123456792',
            'status' => ShipmentStatus::DELIVERED->value,
            'response_payload' => $originalPayload,
            'delivered_at' => $deliveredAt,
        ], [
            'number' => 'ORD202609220004',
            'status' => OrderStatus::DELIVERED->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'ORD202609220004',
            'AllPayLogisticsID' => '123456792',
            'LogisticsStatus' => '2067',
            'LogisticsStatusName' => '已送達重送',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::DELIVERED->value, $shipment->status);
        $this->assertEquals($originalPayload, $shipment->response_payload);
        $this->assertTrue($shipment->delivered_at->equalTo($deliveredAt));
    }

    /**
     * 綠界物流回呼：驗簽失敗時不應更新物流單。
     */
    public function test_callback_rejects_invalid_check_mac_value_without_updating_shipment_state(): void
    {
        $this->setEcpayLogisticsConfig();
        $shipment = $this->createEcpayShipment([
            'tracking_number' => '123456793',
            'status' => ShipmentStatus::CREATED->value,
        ], [
            'number' => 'ORD202609220005',
            'status' => OrderStatus::STOCKING->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'ORD202609220005',
            'AllPayLogisticsID' => '123456793',
            'LogisticsStatus' => '300',
            'CheckMacValue' => str_repeat('A', 32),
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Invalid CheckMacValue');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::CREATED->value, $shipment->status);
        $this->assertNull($shipment->response_payload);
        $this->assertNull($shipment->shipped_at);
        $this->assertSame(OrderStatus::STOCKING->value, $shipment->order->refresh()->status);
    }

    /**
     * 綠界物流回呼：缺少必要欄位時應拒絕 callback。
     */
    public function test_callback_rejects_missing_required_field(): void
    {
        $this->setEcpayLogisticsConfig();
        $payload = $this->signedCallbackPayload([
            'AllPayLogisticsID' => '',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Missing required field: AllPayLogisticsID');
    }

    /**
     * 綠界物流回呼：MerchantID 不符合設定時應拒絕 callback。
     */
    public function test_callback_rejects_invalid_merchant_id(): void
    {
        $this->setEcpayLogisticsConfig();
        $payload = $this->signedCallbackPayload([
            'MerchantID' => '9999999',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Invalid MerchantID');
    }

    /**
     * 綠界物流回呼：找不到對應訂單時應拒絕 callback。
     */
    public function test_callback_rejects_unknown_order(): void
    {
        $this->setEcpayLogisticsConfig();
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'ORD20260922UNKNOWN',
            'AllPayLogisticsID' => '999999999',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertStatus(404)
            ->assertSeeText('0|Order not found');
    }

    /**
     * 綠界物流回呼：找得到訂單但找不到對應物流單時應拒絕 callback。
     */
    public function test_callback_rejects_unknown_shipment(): void
    {
        $this->setEcpayLogisticsConfig();
        Order::factory()->create([
            'number' => 'ORD20260922UNKNOWN',
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'ORD20260922UNKNOWN',
            'AllPayLogisticsID' => '999999999',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertStatus(404)
            ->assertSeeText('0|Shipment not found');
    }

    /**
     * 綠界物流回呼：不支援的物流狀態應確認 callback 且不更新物流單。
     */
    public function test_callback_acknowledges_unsupported_logistics_status_without_updating_shipment(): void
    {
        $this->setEcpayLogisticsConfig();
        $shipment = $this->createEcpayShipment([
            'tracking_number' => '123456794',
            'status' => ShipmentStatus::CREATED->value,
        ], [
            'number' => 'ORD202609220006',
            'status' => OrderStatus::STOCKING->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'MerchantTradeNo' => 'ORD202609220006',
            'AllPayLogisticsID' => '123456794',
            'LogisticsStatus' => '9999',
            'LogisticsStatusName' => '未知狀態',
        ]);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertSeeText('1|OK');

        $shipment->refresh();
        $this->assertSame(ShipmentStatus::CREATED->value, $shipment->status);
        $this->assertNull($shipment->response_payload);
    }

    /**
     * 綠界物流回呼：成功處理時應回傳綠界要求的純文字成功內容。
     */
    public function test_callback_returns_ecpay_success_response_when_service_accepts_payload(): void
    {
        $payload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'ORD202609220007',
            'AllPayLogisticsID' => '123456795',
        ]);

        $service = Mockery::mock(self::SERVICE_CLASS);
        $service->shouldReceive('handle')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 200,
                'content' => '1|OK',
            ]);

        $this->app->instance(self::SERVICE_CLASS, $service);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSeeText('1|OK');
    }

    /**
     * 綠界物流回呼：驗簽或資料比對失敗時應原樣回傳 service 的拒絕結果。
     */
    public function test_callback_propagates_service_rejection_response(): void
    {
        $payload = $this->validCallbackPayload([
            'CheckMacValue' => 'INVALID_CHECK_MAC_VALUE',
        ]);

        $service = Mockery::mock(self::SERVICE_CLASS);
        $service->shouldReceive('handle')
            ->once()
            ->with($payload)
            ->andReturn([
                'status' => 400,
                'content' => '0|Invalid CheckMacValue',
            ]);

        $this->app->instance(self::SERVICE_CLASS, $service);

        $response = $this->post('/api/shipment-callbacks/ecpay', $payload);

        $response->assertStatus(400)
            ->assertSeeText('0|Invalid CheckMacValue');
    }

    /**
     * 綠界物流回呼：同一筆物流交易編號會套用 RateLimiter。
     */
    public function test_callback_is_rate_limited_by_merchant_trade_no(): void
    {
        $payload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'ORD20260922RATE01',
        ]);

        $service = Mockery::mock(self::SERVICE_CLASS);
        $service->shouldReceive('handle')
            ->times(30)
            ->with($payload)
            ->andReturn([
                'status' => 200,
                'content' => '1|OK',
            ]);

        $this->app->instance(self::SERVICE_CLASS, $service);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/api/shipment-callbacks/ecpay', $payload)->assertOk();
        }

        $this->post('/api/shipment-callbacks/ecpay', $payload)->assertStatus(429);
    }

    /**
     * 綠界物流回呼：不同物流交易編號不應互相消耗 callback 層級限制。
     */
    public function test_callback_rate_limit_uses_separate_bucket_for_each_merchant_trade_no(): void
    {
        $firstPayload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'ORD20260922RATE02',
        ]);
        $secondPayload = $this->validCallbackPayload([
            'MerchantTradeNo' => 'ORD20260922RATE03',
        ]);

        $service = Mockery::mock(self::SERVICE_CLASS);
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

        $this->app->instance(self::SERVICE_CLASS, $service);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/api/shipment-callbacks/ecpay', $firstPayload)->assertOk();
        }

        $this->post('/api/shipment-callbacks/ecpay', $secondPayload)->assertOk();
    }

    /**
     * 綠界物流回呼：缺少物流交易編號時會退回以 IP 套用 RateLimiter。
     */
    public function test_callback_without_merchant_trade_no_is_rate_limited_by_ip(): void
    {
        $payload = $this->validCallbackPayload([
            'MerchantTradeNo' => '',
        ]);

        $service = Mockery::mock(self::SERVICE_CLASS);
        $service->shouldReceive('handle')
            ->times(300)
            ->with($payload)
            ->andReturn([
                'status' => 400,
                'content' => '0|Missing required field: MerchantTradeNo',
            ]);

        $this->app->instance(self::SERVICE_CLASS, $service);

        for ($attempt = 0; $attempt < 300; $attempt++) {
            $this->post('/api/shipment-callbacks/ecpay', $payload)->assertStatus(400);
        }

        $this->post('/api/shipment-callbacks/ecpay', $payload)->assertStatus(429);
    }

    /**
     * @param  array<string, mixed>  $shipmentAttributes
     * @param  array<string, mixed>  $orderAttributes
     */
    private function createEcpayShipment(array $shipmentAttributes = [], array $orderAttributes = []): Shipment
    {
        $order = Order::factory()->create(array_merge([
            'number' => 'ORD202609220000',
            'status' => OrderStatus::STOCKING->value,
        ], $orderAttributes));

        return Shipment::factory()->for($order)->create(array_merge([
            'provider' => Provider::ECPAY_LOGISTICS->value,
            'tracking_number' => '123456789',
            'status' => ShipmentStatus::CREATED->value,
            'response_payload' => null,
            'shipped_at' => null,
            'delivered_at' => null,
        ], $shipmentAttributes));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedCallbackPayload(array $overrides = []): array
    {
        $payload = $this->validCallbackPayload($overrides);
        $payloadForSignature = array_map(
            fn (string $value): string => trim($value),
            $payload,
        );
        unset($payloadForSignature['CheckMacValue']);

        $payload['CheckMacValue'] = app(EcpayLogisticsGateway::class)->makeCheckMacValue($payloadForSignature);

        if (array_key_exists('CheckMacValue', $overrides)) {
            $payload['CheckMacValue'] = $overrides['CheckMacValue'];
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validCallbackPayload(array $overrides = []): array
    {
        return array_merge([
            'MerchantID' => '2000132',
            'MerchantTradeNo' => 'ORD202609220000',
            'AllPayLogisticsID' => '123456789',
            'LogisticsType' => 'CVS',
            'LogisticsSubType' => 'UNIMARTC2C',
            'LogisticsStatus' => '300',
            'LogisticsStatusName' => '配送中',
            'GoodsAmount' => '1000',
            'UpdateStatusDate' => '2026/09/22 10:00:00',
            'RtnCode' => '1',
            'RtnMsg' => 'OK',
            'CheckMacValue' => 'PLACEHOLDER_CHECK_MAC_VALUE',
        ], $overrides);
    }

    private function setEcpayLogisticsConfig(): void
    {
        config()->set('services.ecpay_logistics.merchant_id', '2000132');
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');
    }
}
