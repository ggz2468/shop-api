<?php

namespace Tests\Unit;

use App\Enums\Order\Status as OrderStatus;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentDelivered;
use App\Listeners\MarkOrderAsDelivered;
use App\Models\Order;
use App\Models\Shipment;
use App\Repositories\OrderRepository;
use App\Repositories\ShipmentRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarkOrderAsDeliveredTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * ShipmentDelivered: 應標記物流單為已送達並同步訂單狀態。
     */
    public function test_handle_marks_shipment_as_delivered_and_order_as_delivered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00'));
        $order = Order::factory()->create([
            'status' => OrderStatus::DELIVERING->value,
        ]);
        $shipment = Shipment::factory()->for($order)->create([
            'status' => ShipmentStatus::SHIPPED->value,
            'delivered_at' => null,
            'response_payload' => null,
        ]);
        $providerPayload = [
            'MerchantTradeNo' => $order->number,
            'AllPayLogisticsID' => '123456789',
            'LogisticsStatus' => '2067',
        ];

        $this->makeListener()->handle(new ShipmentDelivered($shipment->id, $providerPayload));

        $shipment->refresh();
        $order->refresh();
        $this->assertSame(ShipmentStatus::DELIVERED->value, $shipment->status);
        $this->assertTrue($shipment->delivered_at->equalTo(Carbon::parse('2026-09-23 12:00:00')));
        $this->assertEquals($providerPayload, $shipment->response_payload);
        $this->assertSame(OrderStatus::DELIVERED->value, $order->status);
    }

    /**
     * ShipmentDelivered: 沒有物流商 payload 時仍應更新狀態且不覆寫 response payload。
     */
    public function test_handle_marks_shipment_as_delivered_without_provider_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 13:00:00'));
        $order = Order::factory()->create([
            'status' => OrderStatus::DELIVERING->value,
        ]);
        $shipment = Shipment::factory()->for($order)->create([
            'status' => ShipmentStatus::SHIPPED->value,
            'delivered_at' => null,
            'response_payload' => null,
        ]);

        $this->makeListener()->handle(new ShipmentDelivered($shipment->id));

        $shipment->refresh();
        $order->refresh();
        $this->assertSame(ShipmentStatus::DELIVERED->value, $shipment->status);
        $this->assertTrue($shipment->delivered_at->equalTo(Carbon::parse('2026-09-23 13:00:00')));
        $this->assertNull($shipment->response_payload);
        $this->assertSame(OrderStatus::DELIVERED->value, $order->status);
    }

    /**
     * ShipmentDelivered: 物流單不是已出貨狀態時應直接略過，避免重複轉移狀態。
     */
    public function test_handle_skips_when_shipment_is_not_shipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 13:30:00'));
        $deliveredAt = Carbon::parse('2026-09-23 12:00:00');
        $originalPayload = ['LogisticsStatus' => '2067'];
        $order = Order::factory()->create([
            'status' => OrderStatus::DELIVERED->value,
        ]);
        $shipment = Shipment::factory()->for($order)->create([
            'status' => ShipmentStatus::DELIVERED->value,
            'delivered_at' => $deliveredAt,
            'response_payload' => $originalPayload,
        ]);

        $this->makeListener()->handle(new ShipmentDelivered($shipment->id, [
            'LogisticsStatus' => '2067',
            'RtnMsg' => 'duplicate callback',
        ]));

        $shipment->refresh();
        $order->refresh();
        $this->assertSame(ShipmentStatus::DELIVERED->value, $shipment->status);
        $this->assertTrue($shipment->delivered_at->equalTo($deliveredAt));
        $this->assertEquals($originalPayload, $shipment->response_payload);
        $this->assertSame(OrderStatus::DELIVERED->value, $order->status);
    }

    /**
     * ShipmentDelivered: 找不到物流單時應拋出例外，讓觸發流程可重試或進 failed jobs。
     */
    public function test_handle_throws_model_not_found_exception_when_shipment_is_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->makeListener()->handle(new ShipmentDelivered(999999));
    }

    private function makeListener(): MarkOrderAsDelivered
    {
        return new MarkOrderAsDelivered(
            new OrderRepository,
            new ShipmentRepository,
            app(ConnectionInterface::class),
        );
    }
}
