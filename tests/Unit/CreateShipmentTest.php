<?php

namespace Tests\Unit;

use App\Enums\Order\ShippingMethod as OrderShippingMethod;
use App\Enums\Order\StoreType as OrderStoreType;
use App\Enums\Shipment\Provider;
use App\Enums\Shipment\ShippingMethod;
use App\Enums\Shipment\Status;
use App\Enums\Shipment\StoreType;
use App\Events\PaymentSucceeded;
use App\Events\ShipmentCreated;
use App\Events\ShipmentRequested;
use App\Listeners\CreateShipment;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Shipment;
use App\Repositories\PaymentTransactionRepository;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class CreateShipmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PaymentSucceeded: 應為付款完成的訂單建立待建物流單並 dispatch ShipmentRequested。
     */
    public function test_handle_creates_pending_shipment_and_dispatches_shipment_requested_event(): void
    {
        Event::fake([ShipmentCreated::class, ShipmentRequested::class]);
        config(['services.shipment.default_provider' => Provider::ECPAY_LOGISTICS->value]);

        $paymentTransaction = PaymentTransaction::factory()->create([
            'order_id' => Order::factory()->create([
                'shipping_method' => OrderShippingMethod::CONVENIENCE_STORE->value,
                'recipient_name' => '王小明',
                'recipient_phone' => '0912345678',
                'recipient_address' => null,
                'store_type' => OrderStoreType::UNIMART->value,
                'store_code' => 'UNIMART001',
                'store_name' => '信義門市',
                'store_address' => '台北市信義區測試路 1 號',
            ])->id,
        ]);
        $paymentTransaction->load('order');
        $order = $paymentTransaction->order;

        $this->makeListener()->handle(new PaymentSucceeded($paymentTransaction->id));

        $shipment = Shipment::query()->where('order_id', $paymentTransaction->order_id)->firstOrFail();
        $this->assertSame(Status::PENDING->value, $shipment->status);
        $this->assertSame(StoreType::UNIMART->value, $shipment->store_type);
        $this->assertSame(ShippingMethod::CONVENIENCE_STORE->value, $shipment->shipping_method);
        $this->assertSame('信義門市', $shipment->store_name);
        $this->assertSame('台北市信義區測試路 1 號', $shipment->store_address);
        $this->assertSame(Provider::ECPAY_LOGISTICS->value, $shipment->provider);
        $this->assertNull($shipment->tracking_number);
        $this->assertSame('王小明', $shipment->recipient_name);
        $this->assertSame('0912345678', $shipment->recipient_phone);
        $this->assertNull($shipment->recipient_address);
        $this->assertSame('UNIMART001', $shipment->store_code);
        $this->assertEquals([
            'order_number' => $order->number,
            'provider' => Provider::ECPAY_LOGISTICS->value,
            'shipping_method' => ShippingMethod::CONVENIENCE_STORE->value,
            'recipient' => [
                'name' => '王小明',
                'phone' => '0912345678',
                'address' => null,
            ],
            'store' => [
                'type' => StoreType::UNIMART->value,
                'code' => 'UNIMART001',
                'name' => '信義門市',
                'address' => '台北市信義區測試路 1 號',
            ],
        ], $shipment->request_payload);
        $this->assertNull($shipment->response_payload);
        Event::assertDispatched(
            ShipmentRequested::class,
            fn (ShipmentRequested $event): bool => $event->shipmentId === $shipment->id,
        );
        Event::assertNotDispatched(ShipmentCreated::class);
    }

    /**
     * PaymentSucceeded: 訂單已有出貨單時不應重複建立或再次 dispatch ShipmentRequested。
     */
    public function test_handle_does_not_create_duplicate_shipment_when_order_already_has_one(): void
    {
        Event::fake([ShipmentRequested::class]);
        $paymentTransaction = PaymentTransaction::factory()->create();
        Shipment::factory()->create([
            'order_id' => $paymentTransaction->order_id,
        ]);

        $this->makeListener()->handle(new PaymentSucceeded($paymentTransaction->id));

        $this->assertSame(1, Shipment::query()->where('order_id', $paymentTransaction->order_id)->count());
        Event::assertNotDispatched(ShipmentRequested::class);
    }

    /**
     * PaymentSucceeded: 找不到付款交易時應拋出例外，讓 queue job 可重試或進 failed jobs。
     */
    public function test_handle_throws_model_not_found_exception_when_payment_transaction_is_missing(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->makeListener()->handle(new PaymentSucceeded(999999));
    }

    /**
     * PaymentSucceeded: 不支援的預設物流商設定應拋出例外，避免建立缺少 provider 的出貨單。
     */
    public function test_handle_throws_runtime_exception_when_default_shipment_provider_is_unsupported(): void
    {
        Event::fake([ShipmentRequested::class]);
        config(['services.shipment.default_provider' => 999]);
        $paymentTransaction = PaymentTransaction::factory()->create();

        $this->expectException(RuntimeException::class);

        $this->makeListener()->handle(new PaymentSucceeded($paymentTransaction->id));
    }

    private function makeListener(): CreateShipment
    {
        return new CreateShipment(
            new PaymentTransactionRepository,
            new ShipmentRepository,
            app(Dispatcher::class),
            app('config'),
        );
    }
}
