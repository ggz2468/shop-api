<?php

namespace Tests\Unit;

use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentCreated;
use App\Listeners\SendShipmentCreatedNotification;
use App\Models\Shipment;
use App\Notifications\ShipmentCreatedNotification;
use App\Repositories\ShipmentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendShipmentCreatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ShipmentCreated: 物流單已建立且有追蹤編號時，應通知出貨單所屬訂單的會員。
     */
    public function test_handle_sends_shipment_created_notification_to_order_member(): void
    {
        Notification::fake();

        $shipment = Shipment::factory()->create([
            'status' => ShipmentStatus::CREATED->value,
            'tracking_number' => 'TRK202609090001',
        ]);

        $this->makeListener()->handle(new ShipmentCreated($shipment->id));

        Notification::assertSentTo(
            $shipment->order->member,
            ShipmentCreatedNotification::class,
            fn (ShipmentCreatedNotification $notification): bool => $notification->toArray($shipment->order->member)['shipment_id'] === $shipment->id,
        );
    }

    /**
     * ShipmentCreated: 物流單尚未由物流商建立成功時，不應寄送出貨通知。
     */
    public function test_handle_does_not_send_notification_when_shipment_is_not_created_by_provider(): void
    {
        Notification::fake();

        $shipment = Shipment::factory()->create([
            'status' => ShipmentStatus::PENDING->value,
            'tracking_number' => null,
        ]);

        $this->makeListener()->handle(new ShipmentCreated($shipment->id));

        Notification::assertNothingSent();
    }

    /**
     * ShipmentCreated: 找不到出貨單時應拋出例外，讓 queue job 可重試或進 failed jobs。
     */
    public function test_handle_throws_model_not_found_exception_when_shipment_is_missing(): void
    {
        Notification::fake();

        $this->expectException(ModelNotFoundException::class);

        $this->makeListener()->handle(new ShipmentCreated(999999));
    }

    private function makeListener(): SendShipmentCreatedNotification
    {
        return new SendShipmentCreatedNotification(new ShipmentRepository);
    }
}
