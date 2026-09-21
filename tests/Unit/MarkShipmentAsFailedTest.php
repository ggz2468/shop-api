<?php

namespace Tests\Unit;

use App\Enums\Shipment\Status;
use App\Events\ShipmentFailed;
use App\Listeners\MarkShipmentAsFailed;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkShipmentAsFailedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ShipmentFailed: 應標記物流單失敗並保存物流商回應與失敗原因。
     */
    public function test_handle_marks_shipment_as_failed_with_provider_payload_and_reason(): void
    {
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'response_payload' => null,
        ]);
        $providerPayload = [
            'error_type' => 'http_error',
            'http_status' => 503,
            'body' => 'Service unavailable',
        ];

        $this->makeListener()->handle(new ShipmentFailed($shipment->id, 'HTTP 503', $providerPayload));

        $shipment->refresh();

        $this->assertSame(Status::FAILED->value, $shipment->status);
        $this->assertEquals([
            'error_type' => 'http_error',
            'http_status' => 503,
            'body' => 'Service unavailable',
            'reason' => 'HTTP 503',
        ], $shipment->response_payload);
    }

    /**
     * ShipmentFailed: 沒有物流商回應時應只保存失敗原因。
     */
    public function test_handle_marks_shipment_as_failed_with_reason_only(): void
    {
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'response_payload' => null,
        ]);

        $this->makeListener()->handle(new ShipmentFailed($shipment->id, 'Invalid shipment request'));

        $shipment->refresh();

        $this->assertSame(Status::FAILED->value, $shipment->status);
        $this->assertSame([
            'reason' => 'Invalid shipment request',
        ], $shipment->response_payload);
    }

    /**
     * ShipmentFailed: 沒有物流商回應與原因時，response payload 應保持 null。
     */
    public function test_handle_marks_shipment_as_failed_without_payload_or_reason(): void
    {
        $shipment = Shipment::factory()->create([
            'status' => Status::PENDING->value,
            'response_payload' => null,
        ]);

        $this->makeListener()->handle(new ShipmentFailed($shipment->id));

        $shipment->refresh();

        $this->assertSame(Status::FAILED->value, $shipment->status);
        $this->assertNull($shipment->response_payload);
    }

    /**
     * ShipmentFailed: 找不到物流單時應拋出例外，讓觸發流程可重試或進 failed jobs。
     */
    public function test_handle_throws_model_not_found_exception_when_shipment_is_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->makeListener()->handle(new ShipmentFailed(999999));
    }

    private function makeListener(): MarkShipmentAsFailed
    {
        return new MarkShipmentAsFailed(new ShipmentRepository);
    }
}
