<?php

namespace Tests\Unit;

use App\Enums\Shipment\Provider;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentDelivered;
use App\Events\ShipmentFailed;
use App\Events\ShipmentShipped;
use App\Gateways\Shipments\EcpayLogisticsGateway;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use App\Services\EcpayShipmentCallbackService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class EcpayShipmentCallbackServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 綠界物流回呼 Service: 配送中狀態應 dispatch ShipmentShipped。
     */
    public function test_handle_dispatches_shipment_shipped_for_shipped_status(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $shipment = $this->createEcpayShipment();
        $payload = $this->signedCallbackPayload([
            'AllPayLogisticsID' => '123456789',
            'LogisticsStatus' => '300',
        ]);

        $result = $this->makeService()->handle($payload);

        $this->assertSame(['status' => 200, 'content' => '1|OK'], $result);
        Event::assertDispatched(
            ShipmentShipped::class,
            fn (ShipmentShipped $event): bool => $event->shipmentId === $shipment->id
                && $event->providerPayload['LogisticsStatus'] === '300'
                && ! array_key_exists('CheckMacValue', $event->providerPayload),
        );
        Event::assertNotDispatched(ShipmentDelivered::class);
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * 綠界物流回呼 Service: 已送達狀態應 dispatch ShipmentDelivered。
     */
    public function test_handle_dispatches_shipment_delivered_for_delivered_status(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $shipment = $this->createEcpayShipment([
            'tracking_number' => '123456790',
            'status' => ShipmentStatus::SHIPPED->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'AllPayLogisticsID' => '123456790',
            'LogisticsStatus' => '2067',
        ]);

        $result = $this->makeService()->handle($payload);

        $this->assertSame(['status' => 200, 'content' => '1|OK'], $result);
        Event::assertDispatched(
            ShipmentDelivered::class,
            fn (ShipmentDelivered $event): bool => $event->shipmentId === $shipment->id
                && $event->providerPayload['LogisticsStatus'] === '2067',
        );
        Event::assertNotDispatched(ShipmentShipped::class);
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * 綠界物流回呼 Service: 物流失敗狀態應 dispatch ShipmentFailed。
     */
    public function test_handle_dispatches_shipment_failed_for_failed_status(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $shipment = $this->createEcpayShipment([
            'tracking_number' => '123456791',
        ]);
        $payload = $this->signedCallbackPayload([
            'AllPayLogisticsID' => '123456791',
            'LogisticsStatus' => '2001',
            'RtnMsg' => 'Shipment failed',
        ]);

        $result = $this->makeService()->handle($payload);

        $this->assertSame(['status' => 200, 'content' => '1|OK'], $result);
        Event::assertDispatched(
            ShipmentFailed::class,
            fn (ShipmentFailed $event): bool => $event->shipmentId === $shipment->id
                && $event->reason === 'Shipment failed'
                && $event->providerPayload['LogisticsStatus'] === '2001',
        );
        Event::assertNotDispatched(ShipmentShipped::class);
        Event::assertNotDispatched(ShipmentDelivered::class);
    }

    /**
     * 綠界物流回呼 Service: 已送達物流單收到支援狀態時應直接確認且不 dispatch event。
     */
    public function test_handle_acknowledges_delivered_shipment_without_dispatching_event(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $this->createEcpayShipment([
            'tracking_number' => '123456792',
            'status' => ShipmentStatus::DELIVERED->value,
        ]);
        $payload = $this->signedCallbackPayload([
            'AllPayLogisticsID' => '123456792',
            'LogisticsStatus' => '300',
        ]);

        $result = $this->makeService()->handle($payload);

        $this->assertSame(['status' => 200, 'content' => '1|OK'], $result);
        Event::assertNotDispatched(ShipmentShipped::class);
        Event::assertNotDispatched(ShipmentDelivered::class);
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * 綠界物流回呼 Service: 驗簽失敗時應回傳 400 且不 dispatch event。
     */
    public function test_handle_rejects_invalid_check_mac_value(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $this->createEcpayShipment();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $logger->shouldReceive('error')->never();
        $payload = $this->signedCallbackPayload([
            'CheckMacValue' => str_repeat('A', 32),
        ]);

        $result = $this->makeService($logger)->handle($payload);

        $this->assertSame(['status' => 400, 'content' => '0|Invalid CheckMacValue'], $result);
        Event::assertNotDispatched(ShipmentShipped::class);
        Event::assertNotDispatched(ShipmentDelivered::class);
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * 綠界物流回呼 Service: 缺少必要欄位時應回傳 400。
     */
    public function test_handle_rejects_missing_required_field(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $logger->shouldReceive('error')->never();
        $payload = $this->signedCallbackPayload([
            'AllPayLogisticsID' => '',
        ]);

        $result = $this->makeService($logger)->handle($payload);

        $this->assertSame(['status' => 400, 'content' => '0|Missing required field: AllPayLogisticsID'], $result);
        Event::assertNotDispatched(ShipmentShipped::class);
        Event::assertNotDispatched(ShipmentDelivered::class);
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * 綠界物流回呼 Service: MerchantID 不符時應回傳 400。
     */
    public function test_handle_rejects_invalid_merchant_id(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $logger->shouldReceive('error')->never();
        $payload = $this->signedCallbackPayload([
            'MerchantID' => '9999999',
        ]);

        $result = $this->makeService($logger)->handle($payload);

        $this->assertSame(['status' => 400, 'content' => '0|Invalid MerchantID'], $result);
        Event::assertNotDispatched(ShipmentShipped::class);
        Event::assertNotDispatched(ShipmentDelivered::class);
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * 綠界物流回呼 Service: 找不到物流單時應回傳 404。
     */
    public function test_handle_rejects_unknown_shipment(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $logger->shouldReceive('error')->never();
        $payload = $this->signedCallbackPayload([
            'AllPayLogisticsID' => '999999999',
        ]);

        $result = $this->makeService($logger)->handle($payload);

        $this->assertSame(['status' => 404, 'content' => '0|Shipment not found'], $result);
        Event::assertNotDispatched(ShipmentShipped::class);
        Event::assertNotDispatched(ShipmentDelivered::class);
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * 綠界物流回呼 Service: 不支援的物流狀態應回傳 400。
     */
    public function test_handle_rejects_unsupported_logistics_status(): void
    {
        Event::fake([ShipmentShipped::class, ShipmentDelivered::class, ShipmentFailed::class]);
        $this->setEcpayLogisticsConfig();
        $this->createEcpayShipment();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $logger->shouldReceive('error')->never();
        $payload = $this->signedCallbackPayload([
            'LogisticsStatus' => '9999',
        ]);

        $result = $this->makeService($logger)->handle($payload);

        $this->assertSame(['status' => 400, 'content' => '0|Unsupported LogisticsStatus'], $result);
        Event::assertNotDispatched(ShipmentShipped::class);
        Event::assertNotDispatched(ShipmentDelivered::class);
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEcpayShipment(array $attributes = []): Shipment
    {
        return Shipment::factory()->create(array_merge([
            'provider' => Provider::ECPAY_LOGISTICS->value,
            'tracking_number' => '123456789',
            'status' => ShipmentStatus::CREATED->value,
            'response_payload' => null,
        ], $attributes));
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

    private function makeService(?LoggerInterface $logger = null): EcpayShipmentCallbackService
    {
        return new EcpayShipmentCallbackService(
            new ShipmentRepository,
            app(EcpayLogisticsGateway::class),
            app(Dispatcher::class),
            app('config'),
            $logger ?? Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing(),
        );
    }
}
