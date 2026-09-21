<?php

namespace App\Listeners;

use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentMarkedAsCreated;
use App\Models\Shipment;
use App\Notifications\ShipmentCreatedNotification;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SendShipmentCreatedNotification implements ShouldQueue
{
    /**
     * @return void
     */
    public function __construct(
        private ShipmentRepository $shipmentRepository,
    ) {}

    public function handle(ShipmentMarkedAsCreated $event): void
    {
        $shipment = $this->shipmentRepository->first(['id', $event->shipmentId]);

        if (! $shipment instanceof Shipment) {
            throw new ModelNotFoundException("Shipment with ID {$event->shipmentId} not found.");
        }

        $shipment->loadMissing('order.member');

        if ($shipment->status !== ShipmentStatus::CREATED->value || $shipment->tracking_number === null) {
            return;
        }

        $shipment->order->member->notify(new ShipmentCreatedNotification($shipment));
    }
}
