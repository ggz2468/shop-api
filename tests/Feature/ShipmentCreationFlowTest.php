<?php

namespace Tests\Feature;

use App\Enums\Order\ShippingMethod as OrderShippingMethod;
use App\Enums\Order\StoreType as OrderStoreType;
use App\Enums\Shipment\Provider;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\PaymentSucceeded;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Shipment;
use App\Notifications\ShipmentCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ShipmentCreationFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PaymentSucceeded: 應完成建立物流單、送出綠界物流請求、標記建立成功並寄送通知。
     */
    public function test_payment_succeeded_creates_provider_shipment_and_sends_notification(): void
    {
        Notification::fake();
        Http::fake([
            'https://logistics-stage.ecpay.com.tw/Express/Create' => Http::response(
                'RtnCode=1&RtnMsg=OK&AllPayLogisticsID=123456789&BookingNote=ABC123',
                200,
                ['Content-Type' => 'text/plain'],
            ),
        ]);
        $this->setValidShipmentConfig();
        $paymentTransaction = PaymentTransaction::factory()->create([
            'order_id' => Order::factory()->create([
                'shipping_method' => OrderShippingMethod::CONVENIENCE_STORE->value,
                'store_type' => OrderStoreType::UNIMART->value,
                'store_code' => 'UNIMART001',
                'store_name' => '信義門市',
                'store_address' => '台北市信義區測試路 1 號',
            ])->id,
        ]);

        PaymentSucceeded::dispatch($paymentTransaction->id);

        $shipment = Shipment::query()->where('order_id', $paymentTransaction->order_id)->firstOrFail();
        $this->assertSame(ShipmentStatus::CREATED->value, $shipment->status);
        $this->assertSame('123456789', $shipment->tracking_number);
        $this->assertNotEmpty($shipment->request_payload);
        $this->assertNotEmpty($shipment->checkout_payload);
        $this->assertEquals([
            'RtnCode' => '1',
            'RtnMsg' => 'OK',
            'AllPayLogisticsID' => '123456789',
            'BookingNote' => 'ABC123',
        ], $shipment->response_payload);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://logistics-stage.ecpay.com.tw/Express/Create'
            && $request->method() === 'POST'
            && $request->isForm()
            && $request['MerchantID'] === '2000132'
            && $request['ReceiverStoreID'] === 'UNIMART001'
        );
        Notification::assertSentTo(
            $shipment->order->member,
            ShipmentCreatedNotification::class,
            fn (ShipmentCreatedNotification $notification): bool => $notification->toArray($shipment->order->member)['tracking_number'] === '123456789',
        );
    }

    private function setValidShipmentConfig(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('services.shipment.default_provider', Provider::ECPAY_LOGISTICS->value);
        config()->set('services.ecpay_logistics.merchant_id', '2000132');
        config()->set('services.ecpay_logistics.hash_key', 'XBERn1YOvpM9nfZc');
        config()->set('services.ecpay_logistics.hash_iv', 'h1ONHk4P4yqbl5LK');
        config()->set('services.ecpay_logistics.create_action_url', 'https://logistics-stage.ecpay.com.tw/Express/Create');
        config()->set('services.ecpay_logistics.create_server_reply_url', 'http://localhost/api/shipment-callbacks/ecpay');
        config()->set('services.ecpay_logistics.sender_name', 'Shop API');
        config()->set('services.ecpay_logistics.sender_cell_phone', '0911222333');
    }
}
