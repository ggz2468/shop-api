<?php

namespace App\Listeners;

use App\Enums\Order\Status as OrderStatus;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentDelivered;
use App\Models\Shipment;
use App\Repositories\OrderRepository;
use App\Repositories\ShipmentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MarkOrderAsDelivered
{
    /**
     * @return void
     */
    public function __construct(
        private ShipmentRepository $shipmentRepository,
        private OrderRepository $orderRepository,
    ) {}

    public function handle(ShipmentDelivered $event): void
    {
        $shipment = $this->shipmentRepository->first(['id', $event->shipmentId]);

        if (! $shipment instanceof Shipment) {
            throw new ModelNotFoundException("Shipment with ID {$event->shipmentId} not found.");
        }

        $shipmentData = [
            'status' => ShipmentStatus::DELIVERED->value,
            'delivered_at' => now(),
        ];

        if ($event->providerPayload !== null) {
            $shipmentData['response_payload'] = $event->providerPayload;
        }

        $this->shipmentRepository->update(['id', $shipment->id], $shipmentData);
        $this->orderRepository->update(['id', $shipment->order_id], [
            'status' => OrderStatus::DELIVERED->value,
        ]);
    }
}
