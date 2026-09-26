<?php

namespace App\Listeners;

use App\Enums\Order\ShippingMethod as OrderShippingMethod;
use App\Enums\Order\StoreType as OrderStoreType;
use App\Enums\Shipment\Provider;
use App\Enums\Shipment\ShippingMethod;
use App\Enums\Shipment\Status;
use App\Enums\Shipment\StoreType;
use App\Events\PaymentSucceeded;
use App\Events\ShipmentRequested;
use App\Models\PaymentTransaction;
use App\Repositories\PaymentTransactionRepository;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

class CreateShipment
{
    /**
     * @return void
     */
    public function __construct(
        private PaymentTransactionRepository $paymentTransactionRepository,
        private ShipmentRepository $shipmentRepository,
        private Dispatcher $events,
        private ConfigRepository $config,
    ) {}

    public function handle(PaymentSucceeded $event): void
    {
        $paymentTransaction = $this->paymentTransactionRepository->first(['id', $event->paymentTransactionId]);

        if (! $paymentTransaction instanceof PaymentTransaction) {
            throw new ModelNotFoundException("Payment transaction with ID {$event->paymentTransactionId} not found.");
        }

        $existingShipment = $this->shipmentRepository->first(['order_id', $paymentTransaction->order_id]);

        if ($existingShipment !== null) {
            return;
        }

        $paymentTransaction->load('order');
        $order = $paymentTransaction->order;

        if ($order === null) {
            throw new ModelNotFoundException("Order with ID {$paymentTransaction->order_id} not found.");
        }

        $provider = $this->resolveDefaultProvider();
        $shippingMethod = $this->resolveShippingMethod($order->shipping_method);
        $recipientData = [
            'name' => $order->recipient_name,
            'phone' => $order->recipient_phone,
            'address' => $order->recipient_address,
        ];
        $storeData = [
            'type' => $this->resolveStoreType($order->store_type)?->value,
            'code' => $order->store_code,
            'name' => $order->store_name,
            'address' => $order->store_address,
        ];

        $shipment = $this->shipmentRepository->create([
            'order_id' => $order->id,
            'provider' => $provider->value,
            'tracking_number' => null,
            'status' => Status::PENDING->value,
            'shipping_method' => $shippingMethod->value,
            'recipient_name' => $recipientData['name'],
            'recipient_phone' => $recipientData['phone'],
            'recipient_address' => $recipientData['address'],
            'store_code' => $storeData['code'],
            'store_type' => $storeData['type'],
            'store_name' => $storeData['name'],
            'store_address' => $storeData['address'],
            'request_payload' => [
                'order_number' => $order->number,
                'provider' => $provider->value,
                'shipping_method' => $shippingMethod->value,
                'recipient' => $recipientData,
                'store' => $storeData,
            ],
            'response_payload' => null,
        ]);

        $this->events->dispatch(new ShipmentRequested($shipment->id));
    }

    private function resolveDefaultProvider(): Provider
    {
        $provider = Provider::tryFrom((int) $this->config->get('services.shipment.default_provider'));

        if (! $provider instanceof Provider) {
            throw new RuntimeException('Default shipment provider is not supported.');
        }

        return $provider;
    }

    private function resolveShippingMethod(int $shippingMethod): ShippingMethod
    {
        return match (OrderShippingMethod::from($shippingMethod)) {
            OrderShippingMethod::HOME_DELIVERY => ShippingMethod::HOME_DELIVERY,
            OrderShippingMethod::CONVENIENCE_STORE => ShippingMethod::CONVENIENCE_STORE,
        };
    }

    private function resolveStoreType(?string $storeType): ?StoreType
    {
        if ($storeType === null) {
            return null;
        }

        return match (OrderStoreType::from($storeType)) {
            OrderStoreType::UNIMART => StoreType::UNIMART,
            OrderStoreType::FAMI => StoreType::FAMI,
            OrderStoreType::HILIFE => StoreType::HILIFE,
        };
    }
}
