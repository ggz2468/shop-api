<?php

namespace Tests\Unit;

use App\Enums\Order\Status as OrderStatus;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentShipped;
use App\Listeners\MarkOrderAsDelivering;
use App\Models\Order;
use App\Models\Shipment;
use App\Repositories\OrderRepository;
use App\Repositories\ShipmentRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MarkOrderAsDeliveringTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * ShipmentShipped: 應標記物流單為配送中並同步訂單狀態。
     */
    public function test_handle_marks_shipment_as_shipped_and_order_as_delivering(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 10:00:00'));
        $order = Order::factory()->create([
            'status' => OrderStatus::STOCKING->value,
        ]);
        $shipment = Shipment::factory()->for($order)->create([
            'status' => ShipmentStatus::CREATED->value,
            'shipped_at' => null,
            'response_payload' => null,
        ]);
        $providerPayload = [
            'MerchantTradeNo' => $order->number,
            'AllPayLogisticsID' => '123456789',
            'LogisticsStatus' => '300',
        ];

        $this->makeListener()->handle(new ShipmentShipped($shipment->id, $providerPayload));

        $shipment->refresh();
        $order->refresh();
        $this->assertSame(ShipmentStatus::SHIPPED->value, $shipment->status);
        $this->assertTrue($shipment->shipped_at->equalTo(Carbon::parse('2026-09-23 10:00:00')));
        $this->assertEquals($providerPayload, $shipment->response_payload);
        $this->assertSame(OrderStatus::DELIVERING->value, $order->status);
    }

    /**
     * ShipmentShipped: 沒有物流商 payload 時仍應更新狀態且不覆寫 response payload。
     */
    public function test_handle_marks_shipment_as_shipped_without_provider_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 11:00:00'));
        $order = Order::factory()->create([
            'status' => OrderStatus::STOCKING->value,
        ]);
        $shipment = Shipment::factory()->for($order)->create([
            'status' => ShipmentStatus::CREATED->value,
            'shipped_at' => null,
            'response_payload' => null,
        ]);

        $this->makeListener()->handle(new ShipmentShipped($shipment->id));

        $shipment->refresh();
        $order->refresh();
        $this->assertSame(ShipmentStatus::SHIPPED->value, $shipment->status);
        $this->assertTrue($shipment->shipped_at->equalTo(Carbon::parse('2026-09-23 11:00:00')));
        $this->assertNull($shipment->response_payload);
        $this->assertSame(OrderStatus::DELIVERING->value, $order->status);
    }

    /**
     * ShipmentShipped: 物流單不是已建立狀態時應直接略過，避免重複轉移狀態。
     */
    public function test_handle_skips_when_shipment_is_not_created(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 11:30:00'));
        $shippedAt = Carbon::parse('2026-09-23 09:00:00');
        $originalPayload = ['LogisticsStatus' => '300'];
        $order = Order::factory()->create([
            'status' => OrderStatus::DELIVERING->value,
        ]);
        $shipment = Shipment::factory()->for($order)->create([
            'status' => ShipmentStatus::SHIPPED->value,
            'shipped_at' => $shippedAt,
            'response_payload' => $originalPayload,
        ]);

        $this->makeListener()->handle(new ShipmentShipped($shipment->id, [
            'LogisticsStatus' => '300',
            'RtnMsg' => 'duplicate callback',
        ]));

        $shipment->refresh();
        $order->refresh();
        $this->assertSame(ShipmentStatus::SHIPPED->value, $shipment->status);
        $this->assertTrue($shipment->shipped_at->equalTo($shippedAt));
        $this->assertEquals($originalPayload, $shipment->response_payload);
        $this->assertSame(OrderStatus::DELIVERING->value, $order->status);
    }

    /**
     * ShipmentShipped: 找不到物流單時應拋出例外，讓觸發流程可重試或進 failed jobs。
     */
    public function test_handle_throws_model_not_found_exception_when_shipment_is_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->makeListener()->handle(new ShipmentShipped(999999));
    }

    private function makeListener(): MarkOrderAsDelivering
    {
        return new MarkOrderAsDelivering(
            new OrderRepository,
            new ShipmentRepository,
            app(ConnectionInterface::class),
        );
    }
}
