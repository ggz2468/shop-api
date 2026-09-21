<?php

namespace Tests\Unit;

use App\Enums\Shipment\Status;
use App\Events\ShipmentCreated;
use App\Events\ShipmentMarkedAsCreated;
use App\Listeners\MarkShipmentAsCreated;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MarkShipmentAsCreatedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ShipmentCreated: 應標記物流單建立成功並保存物流商回應。
     */
    public function test_handle_marks_shipment_as_created(): void
    {
        Event::fake([ShipmentMarkedAsCreated::class]);
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'tracking_number' => null,
            'response_payload' => null,
        ]);
        $providerPayload = [
            'RtnCode' => '1',
            'RtnMsg' => 'OK',
            'AllPayLogisticsID' => '123456789',
            'BookingNote' => 'ABC123',
        ];

        $this->makeListener()->handle(new ShipmentCreated($shipment->id, $providerPayload));

        $shipment->refresh();

        $this->assertSame(Status::CREATED->value, $shipment->status);
        $this->assertSame('123456789', $shipment->tracking_number);
        $this->assertEquals($providerPayload, $shipment->response_payload);
        Event::assertDispatched(
            ShipmentMarkedAsCreated::class,
            fn (ShipmentMarkedAsCreated $event): bool => $event->shipmentId === $shipment->id,
        );
    }

    /**
     * ShipmentCreated: 沒有物流商回應時仍應標記建立成功，並保持 response payload 為 null。
     */
    public function test_handle_marks_shipment_as_created_without_provider_payload(): void
    {
        Event::fake([ShipmentMarkedAsCreated::class]);
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'tracking_number' => null,
            'response_payload' => null,
        ]);

        $this->makeListener()->handle(new ShipmentCreated($shipment->id));

        $shipment->refresh();

        $this->assertSame(Status::CREATED->value, $shipment->status);
        $this->assertNull($shipment->tracking_number);
        $this->assertNull($shipment->response_payload);
        Event::assertDispatched(
            ShipmentMarkedAsCreated::class,
            fn (ShipmentMarkedAsCreated $event): bool => $event->shipmentId === $shipment->id,
        );
    }

    /**
     * ShipmentCreated: 找不到物流單時應拋出例外，讓觸發流程可重試或進 failed jobs。
     */
    public function test_handle_throws_model_not_found_exception_when_shipment_is_missing(): void
    {
        Event::fake([ShipmentMarkedAsCreated::class]);

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->makeListener()->handle(new ShipmentCreated(999999));
        } finally {
            Event::assertNotDispatched(ShipmentMarkedAsCreated::class);
        }
    }

    private function makeListener(): MarkShipmentAsCreated
    {
        return new MarkShipmentAsCreated(
            new ShipmentRepository,
            app(Dispatcher::class),
        );
    }
}
