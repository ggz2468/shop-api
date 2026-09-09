<?php

namespace Tests\Unit;

use App\Enums\Shipment\Status as ShipmentStatus;
use App\Models\Shipment;
use App\Notifications\ShipmentCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentCreatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ShipmentCreatedNotification: 應透過 mail channel 發送出貨資訊。
     */
    public function test_via_returns_mail_channel(): void
    {
        $shipment = Shipment::factory()->create([
            'status' => ShipmentStatus::CREATED->value,
        ]);
        $notification = new ShipmentCreatedNotification($shipment);

        $this->assertSame(['mail'], $notification->via($shipment->order->member));
    }

    /**
     * ShipmentCreatedNotification: 應建立出貨通知郵件內容。
     */
    public function test_to_mail_returns_shipment_created_mail_message(): void
    {
        $shipment = Shipment::factory()->create([
            'status' => ShipmentStatus::CREATED->value,
            'tracking_number' => 'TRK202609060001',
            'recipient_name' => '王小明',
            'recipient_phone' => '0911222333',
            'recipient_address' => '台北市信義區測試路 1 號',
        ]);
        $notification = new ShipmentCreatedNotification($shipment);

        $mailMessage = $notification->toMail($shipment->order->member);

        $this->assertSame('出貨資訊已建立', $mailMessage->subject);
        $this->assertSame('emails.shipment-created', $mailMessage->view);
        $this->assertSame($shipment->order->number, $mailMessage->viewData['orderNumber']);
        $this->assertSame('TRK202609060001', $mailMessage->viewData['trackingNumber']);
        $this->assertSame('王小明', $mailMessage->viewData['recipientName']);
        $this->assertSame('0911222333', $mailMessage->viewData['recipientPhone']);
        $this->assertSame('台北市信義區測試路 1 號', $mailMessage->viewData['recipientAddress']);
    }

    /**
     * ShipmentCreatedNotification: 應提供通知陣列內容。
     */
    public function test_to_array_returns_shipment_identifiers(): void
    {
        $shipment = Shipment::factory()->create([
            'status' => ShipmentStatus::CREATED->value,
            'tracking_number' => 'TRK202609060002',
        ]);
        $notification = new ShipmentCreatedNotification($shipment);

        $this->assertSame([
            'shipment_id' => $shipment->id,
            'order_id' => $shipment->order_id,
            'tracking_number' => 'TRK202609060002',
        ], $notification->toArray($shipment->order->member));
    }
}
