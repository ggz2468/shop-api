<?php

namespace App\Listeners;

use App\Enums\Order\Status as OrderStatus;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentDelivered;
use App\Models\Shipment;
use App\Repositories\OrderRepository;
use App\Repositories\ShipmentRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

class MarkOrderAsDelivered
{
    /**
     * @return void
     */
    public function __construct(
        private OrderRepository $orderRepository,
        private ShipmentRepository $shipmentRepository,
        private ConnectionInterface $db,
    ) {}

    public function handle(ShipmentDelivered $event): void
    {
        $this->db->transaction(function () use ($event) {
            $shipment = $this->shipmentRepository->first(['id', $event->shipmentId]);

            if (! $shipment instanceof Shipment) {
                throw new ModelNotFoundException("Shipment with ID {$event->shipmentId} not found.");
            }

            if ($shipment->status !== ShipmentStatus::SHIPPED->value) {
                return;
            }

            $shipmentData = [
                'status' => ShipmentStatus::DELIVERED->value,
                'delivered_at' => now(),
            ];

            if ($event->providerPayload !== null) {
                $shipmentData['response_payload'] = $event->providerPayload;
            }

            if ($this->shipmentRepository->update([
                ['id', $shipment->id],
                ['status', ShipmentStatus::SHIPPED->value],
            ], $shipmentData) < 1) {
                throw new RuntimeException("Failed to update shipment with ID {$shipment->id}.");
            }

            if ($this->orderRepository->update(['id', $shipment->order_id], [
                'status' => OrderStatus::DELIVERED->value,
            ]) < 1) {
                throw new RuntimeException("Failed to update order with ID {$shipment->order_id}.");
            }
        });
    }
}
