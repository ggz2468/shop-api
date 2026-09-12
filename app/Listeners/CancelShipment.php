<?php

namespace App\Listeners;

use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\OrderCanceled;
use App\Repositories\ShipmentRepository;

class CancelShipment
{
    /**
     * @return void
     */
    public function __construct(
        private ShipmentRepository $shipmentRepository,
    ) {}

    public function handle(OrderCanceled $event): void
    {
        $shipments = $this->shipmentRepository->get(['order_id', $event->orderId]);
        $cancelableStatuses = [
            ShipmentStatus::PENDING->value,
            ShipmentStatus::CREATED->value,
        ];

        foreach ($shipments as $shipment) {
            if (! in_array($shipment->status, $cancelableStatuses, true)) {
                continue;
            }

            $this->shipmentRepository->update(['id', $shipment->id], [
                'status' => ShipmentStatus::CANCELED->value,
            ]);
        }
    }
}
