<?php

namespace App\Listeners;

use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentCreated;
use App\Events\ShipmentMarkedAsCreated;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MarkShipmentAsCreated
{
    /**
     * @return void
     */
    public function __construct(
        private ShipmentRepository $shipmentRepository,
        private Dispatcher $events,
    ) {}

    public function handle(ShipmentCreated $event): void
    {
        $shipment = $this->shipmentRepository->first(['id', $event->shipmentId]);

        if (! $shipment instanceof Shipment) {
            throw new ModelNotFoundException("Shipment not found with ID {$event->shipmentId}");
        }

        $responsePayload = $event->providerPayload ?? [];

        $this->shipmentRepository->update(['id', $shipment->id], [
            'tracking_number' => $responsePayload['AllPayLogisticsID'] ?? null,
            'status' => ShipmentStatus::CREATED->value,
            'response_payload' => $responsePayload === [] ? null : $responsePayload,
        ]);

        $this->events->dispatch(new ShipmentMarkedAsCreated($shipment->id));
    }
}
