<?php

namespace Tests\Unit;

use App\Enums\Shipment\Status;
use App\Events\ShipmentCreated;
use App\Events\ShipmentFailed;
use App\Events\ShipmentRequestPayloadBuilt;
use App\Listeners\SubmitShipmentRequest;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class SubmitShipmentRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * ShipmentRequestPayloadBuilt: listener 應進入 queue，避免綠界 API I/O 阻塞主流程。
     */
    public function test_listener_should_be_queued(): void
    {
        $listener = $this->makeListener(Mockery::mock(LoggerInterface::class));

        $this->assertInstanceOf(ShouldQueue::class, $listener);
    }

    /**
     * ShipmentRequestPayloadBuilt: 應以已保存的 checkout/request payload 呼叫綠界物流 API 並 dispatch ShipmentCreated。
     */
    public function test_handle_submits_shipment_request_and_marks_shipment_created(): void
    {
        Event::fake([ShipmentCreated::class, ShipmentFailed::class]);
        Http::fake([
            'https://logistics-stage.ecpay.com.tw/Express/Create' => Http::response(
                'RtnCode=1&RtnMsg=OK&AllPayLogisticsID=123456789&BookingNote=ABC123',
                200,
                ['Content-Type' => 'text/plain'],
            ),
        ]);
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'tracking_number' => null,
            'request_payload' => [
                'MerchantID' => '2000132',
                'MerchantTradeNo' => 'ORD202609200001',
                'CheckMacValue' => 'CHECK_MAC_VALUE',
            ],
            'checkout_payload' => [
                'action' => 'https://logistics-stage.ecpay.com.tw/Express/Create',
                'method' => 'POST',
            ],
            'response_payload' => null,
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')
            ->once()
            ->with('Shipment request is submitted.', Mockery::on(fn (array $context): bool => $context['shipment_id'] === $shipment->id));

        $this->makeListener($logger)->handle(new ShipmentRequestPayloadBuilt($shipment->id));

        $shipment->refresh();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://logistics-stage.ecpay.com.tw/Express/Create'
            && $request->method() === 'POST'
            && $request->isForm()
            && $request['MerchantTradeNo'] === 'ORD202609200001'
            && $request['CheckMacValue'] === 'CHECK_MAC_VALUE'
        );
        $this->assertSame(Status::PENDING->value, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->response_payload);
        Event::assertDispatched(
            ShipmentCreated::class,
            fn (ShipmentCreated $event): bool => $event->shipmentId === $shipment->id
                && $event->providerPayload === [
                    'RtnCode' => '1',
                    'RtnMsg' => 'OK',
                    'AllPayLogisticsID' => '123456789',
                    'BookingNote' => 'ABC123',
                ],
        );
        Event::assertNotDispatched(ShipmentFailed::class);
    }

    /**
     * ShipmentRequestPayloadBuilt: 找不到物流單時應拋出例外，且不呼叫綠界 API。
     */
    public function test_handle_throws_model_not_found_exception_when_shipment_is_missing(): void
    {
        Event::fake([ShipmentCreated::class, ShipmentFailed::class]);
        Http::fake();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->never();
        $logger->shouldReceive('warning')->never();

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->makeListener($logger)->handle(new ShipmentRequestPayloadBuilt(999999));
        } finally {
            Http::assertNothingSent();
            Event::assertNotDispatched(ShipmentCreated::class);
            Event::assertNotDispatched(ShipmentFailed::class);
        }
    }

    /**
     * ShipmentRequestPayloadBuilt: 缺少 request payload 時應拋出例外且不呼叫綠界 API。
     */
    public function test_handle_throws_runtime_exception_when_request_payload_is_missing(): void
    {
        Event::fake([ShipmentCreated::class, ShipmentFailed::class]);
        Http::fake();
        $shipment = Shipment::factory()->create([
            'request_payload' => null,
            'checkout_payload' => [
                'action' => 'https://logistics-stage.ecpay.com.tw/Express/Create',
                'method' => 'POST',
            ],
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->never();
        $logger->shouldReceive('warning')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shipment request payload is not ready.');

        try {
            $this->makeListener($logger)->handle(new ShipmentRequestPayloadBuilt($shipment->id));
        } finally {
            Http::assertNothingSent();
            Event::assertNotDispatched(ShipmentCreated::class);
            Event::assertNotDispatched(ShipmentFailed::class);
        }
    }

    /**
     * ShipmentRequestPayloadBuilt: 缺少 checkout payload 時應拋出例外且不呼叫綠界 API。
     */
    public function test_handle_throws_runtime_exception_when_checkout_payload_is_missing(): void
    {
        Event::fake([ShipmentCreated::class, ShipmentFailed::class]);
        Http::fake();
        $shipment = Shipment::factory()->create([
            'request_payload' => [
                'MerchantTradeNo' => 'ORD202609200002',
            ],
            'checkout_payload' => null,
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->never();
        $logger->shouldReceive('warning')->never();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shipment checkout payload is not ready.');

        try {
            $this->makeListener($logger)->handle(new ShipmentRequestPayloadBuilt($shipment->id));
        } finally {
            Http::assertNothingSent();
            Event::assertNotDispatched(ShipmentCreated::class);
            Event::assertNotDispatched(ShipmentFailed::class);
        }
    }

    /**
     * ShipmentRequestPayloadBuilt: 綠界回應失敗時應 dispatch ShipmentFailed。
     */
    public function test_handle_marks_shipment_failed_when_ecpay_returns_failed_response(): void
    {
        Event::fake([ShipmentCreated::class, ShipmentFailed::class]);
        Http::fake([
            'https://logistics-stage.ecpay.com.tw/Express/Create' => Http::response(
                'RtnCode=0&RtnMsg=Invalid shipment request',
                200,
                ['Content-Type' => 'text/plain'],
            ),
        ]);
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'tracking_number' => null,
            'request_payload' => [
                'MerchantTradeNo' => 'ORD202609200003',
            ],
            'checkout_payload' => [
                'action' => 'https://logistics-stage.ecpay.com.tw/Express/Create',
                'method' => 'POST',
            ],
            'response_payload' => null,
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('Shipment request is rejected by provider.', Mockery::on(fn (array $context): bool => $context['shipment_id'] === $shipment->id));
        $logger->shouldReceive('info')->never();

        $this->makeListener($logger)->handle(new ShipmentRequestPayloadBuilt($shipment->id));

        $shipment->refresh();

        $this->assertSame(Status::PENDING->value, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->response_payload);
        Event::assertDispatched(
            ShipmentFailed::class,
            fn (ShipmentFailed $event): bool => $event->shipmentId === $shipment->id
                && $event->reason === 'Invalid shipment request'
                && $event->providerPayload === [
                    'RtnCode' => '0',
                    'RtnMsg' => 'Invalid shipment request',
                ],
        );
        Event::assertNotDispatched(ShipmentCreated::class);
    }

    /**
     * ShipmentRequestPayloadBuilt: 綠界 HTTP 失敗時應 dispatch ShipmentFailed。
     */
    public function test_handle_marks_shipment_failed_when_http_request_fails(): void
    {
        Event::fake([ShipmentCreated::class, ShipmentFailed::class]);
        Http::fake([
            'https://logistics-stage.ecpay.com.tw/Express/Create' => Http::response('Service unavailable', 503),
        ]);
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'tracking_number' => null,
            'request_payload' => [
                'MerchantTradeNo' => 'ORD202609200004',
            ],
            'checkout_payload' => [
                'action' => 'https://logistics-stage.ecpay.com.tw/Express/Create',
                'method' => 'POST',
            ],
            'response_payload' => null,
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('Shipment request HTTP error.', Mockery::on(fn (array $context): bool => $context['shipment_id'] === $shipment->id));
        $logger->shouldReceive('info')->never();

        $this->makeListener($logger)->handle(new ShipmentRequestPayloadBuilt($shipment->id));

        $shipment->refresh();

        $this->assertSame(Status::PENDING->value, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->response_payload);
        Event::assertDispatched(
            ShipmentFailed::class,
            fn (ShipmentFailed $event): bool => $event->shipmentId === $shipment->id
                && $event->reason === 'HTTP 503'
                && $event->providerPayload === [
                    'error_type' => 'http_error',
                    'http_status' => 503,
                    'body' => 'Service unavailable',
                ],
        );
        Event::assertNotDispatched(ShipmentCreated::class);
    }

    /**
     * ShipmentRequestPayloadBuilt: 綠界回應格式缺少 RtnCode 時應 dispatch ShipmentFailed。
     */
    public function test_handle_marks_shipment_failed_when_provider_response_does_not_include_rtn_code(): void
    {
        Event::fake([ShipmentCreated::class, ShipmentFailed::class]);
        Http::fake([
            'https://logistics-stage.ecpay.com.tw/Express/Create' => Http::response(
                'Unexpected provider response',
                200,
                ['Content-Type' => 'text/plain'],
            ),
        ]);
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'tracking_number' => null,
            'request_payload' => [
                'MerchantTradeNo' => 'ORD202609200005',
            ],
            'checkout_payload' => [
                'action' => 'https://logistics-stage.ecpay.com.tw/Express/Create',
                'method' => 'POST',
            ],
            'response_payload' => null,
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('Shipment request returned invalid provider response.', Mockery::on(fn (array $context): bool => $context['shipment_id'] === $shipment->id));
        $logger->shouldReceive('info')->never();

        $this->makeListener($logger)->handle(new ShipmentRequestPayloadBuilt($shipment->id));

        $shipment->refresh();

        $this->assertSame(Status::PENDING->value, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->response_payload);
        Event::assertDispatched(
            ShipmentFailed::class,
            fn (ShipmentFailed $event): bool => $event->shipmentId === $shipment->id
                && $event->reason === 'Invalid shipment provider response.'
                && $event->providerPayload === [
                    'error_type' => 'invalid_provider_response',
                    'body' => 'Unexpected provider response',
                    'parsed_payload' => [
                        'Unexpected_provider_response' => '',
                    ],
                ],
        );
        Event::assertNotDispatched(ShipmentCreated::class);
    }

    private function makeListener(LoggerInterface $logger): SubmitShipmentRequest
    {
        return new SubmitShipmentRequest(
            app(HttpFactory::class),
            new ShipmentRepository,
            $logger,
            app(Dispatcher::class),
        );
    }
}
