<?php

namespace Tests\Unit;

use App\Contracts\ShipmentGateway;
use App\Events\ShipmentRequested;
use App\Events\ShipmentRequestPayloadBuilt;
use App\Gateways\Shipments\ShipmentGatewayManager;
use App\Listeners\BuildShipmentRequestPayload;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class BuildShipmentRequestPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * ShipmentRequested: listener 應進入 queue，避免第三方物流 payload 建立流程阻塞主流程。
     */
    public function test_listener_should_be_queued(): void
    {
        $listener = new BuildShipmentRequestPayload(
            new ShipmentRepository,
            Mockery::mock(ShipmentGatewayManager::class),
            Mockery::mock(LoggerInterface::class),
            app(Dispatcher::class),
        );

        $this->assertInstanceOf(ShouldQueue::class, $listener);
    }

    /**
     * ShipmentRequested: 應建立物流 request payload 與 checkout payload，且不覆寫 response payload。
     */
    public function test_handle_builds_shipment_request_payload(): void
    {
        Event::fake([ShipmentRequestPayloadBuilt::class]);

        $shipment = Shipment::factory()->create([
            'request_payload' => [
                'order_number' => 'ORD202609190001',
            ],
            'checkout_payload' => null,
            'response_payload' => [
                'RtnCode' => '1',
            ],
        ]);
        $shipmentRequest = [
            'action' => 'https://logistics-stage.ecpay.com.tw/Express/Create',
            'method' => 'POST',
            'params' => [
                'MerchantID' => '2000132',
                'MerchantTradeNo' => 'ORD202609190001',
                'CheckMacValue' => 'CHECK_MAC_VALUE',
            ],
        ];

        $shipmentGateway = Mockery::mock(ShipmentGateway::class);
        $shipmentGateway->shouldReceive('buildShipmentRequest')
            ->once()
            ->with(Mockery::on(fn ($argument): bool => $argument instanceof Shipment && $argument->id === $shipment->id))
            ->andReturn($shipmentRequest);
        $shipmentGatewayManager = Mockery::mock(ShipmentGatewayManager::class);
        $shipmentGatewayManager->shouldReceive('driver')
            ->once()
            ->with($shipment->provider)
            ->andReturn($shipmentGateway);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('Shipment request payload is ready.', Mockery::on(fn (array $context): bool => $context['shipment_id'] === $shipment->id));

        $listener = new BuildShipmentRequestPayload(
            new ShipmentRepository,
            $shipmentGatewayManager,
            $logger,
            app(Dispatcher::class),
        );

        $listener->handle(new ShipmentRequested($shipment->id));

        $shipment->refresh();

        $this->assertEquals($shipmentRequest['params'], $shipment->request_payload);
        $this->assertSame([
            'action' => $shipmentRequest['action'],
            'method' => $shipmentRequest['method'],
        ], $shipment->checkout_payload);
        $this->assertSame([
            'RtnCode' => '1',
        ], $shipment->response_payload);
        Event::assertDispatched(
            ShipmentRequestPayloadBuilt::class,
            fn (ShipmentRequestPayloadBuilt $event): bool => $event->shipmentId === $shipment->id,
        );
    }

    /**
     * ShipmentRequested: 找不到物流單時應拋出例外，讓 queue job 可被 retry / failed job 追蹤。
     */
    public function test_handle_throws_model_not_found_exception_when_shipment_is_missing(): void
    {
        Event::fake([ShipmentRequestPayloadBuilt::class]);

        $shipmentGatewayManager = Mockery::mock(ShipmentGatewayManager::class);
        $shipmentGatewayManager->shouldReceive('driver')->never();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->never();

        $listener = new BuildShipmentRequestPayload(
            new ShipmentRepository,
            $shipmentGatewayManager,
            $logger,
            app(Dispatcher::class),
        );

        $exception = null;

        try {
            $listener->handle(new ShipmentRequested(999999));
        } catch (ModelNotFoundException $exception) {
        }

        $this->assertInstanceOf(ModelNotFoundException::class, $exception);
        Event::assertNotDispatched(ShipmentRequestPayloadBuilt::class);
    }

    /**
     * ShipmentRequested: gateway 建立 payload 失敗時不應更新 shipment，讓例外向外拋出供 queue 重試。
     */
    public function test_handle_does_not_update_shipment_when_gateway_fails(): void
    {
        Event::fake([ShipmentRequestPayloadBuilt::class]);

        $shipment = Shipment::factory()->create([
            'request_payload' => [
                'order_number' => 'ORD202609190002',
            ],
            'checkout_payload' => [
                'action' => 'https://old.example.test',
                'method' => 'POST',
            ],
            'response_payload' => null,
        ]);

        $shipmentGateway = Mockery::mock(ShipmentGateway::class);
        $shipmentGateway->shouldReceive('buildShipmentRequest')
            ->once()
            ->andThrow(new RuntimeException('Ecpay logistics request payload cannot be built.'));
        $shipmentGatewayManager = Mockery::mock(ShipmentGatewayManager::class);
        $shipmentGatewayManager->shouldReceive('driver')
            ->once()
            ->with($shipment->provider)
            ->andReturn($shipmentGateway);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->never();

        $listener = new BuildShipmentRequestPayload(
            new ShipmentRepository,
            $shipmentGatewayManager,
            $logger,
            app(Dispatcher::class),
        );
        $exception = null;

        try {
            $listener->handle(new ShipmentRequested($shipment->id));
        } catch (RuntimeException $exception) {
        }

        $shipment->refresh();

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertSame('Ecpay logistics request payload cannot be built.', $exception->getMessage());
        $this->assertSame([
            'order_number' => 'ORD202609190002',
        ], $shipment->request_payload);
        $this->assertSame([
            'action' => 'https://old.example.test',
            'method' => 'POST',
        ], $shipment->checkout_payload);
        $this->assertNull($shipment->response_payload);
        Event::assertNotDispatched(ShipmentRequestPayloadBuilt::class);
    }
}
