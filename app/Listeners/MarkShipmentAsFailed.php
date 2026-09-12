<?php

namespace App\Listeners;

use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentFailed;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class MarkShipmentAsFailed
{
    /**
     * @return void
     */
    public function __construct(
        private ShipmentRepository $shipmentRepository,
    ) {}

    public function handle(ShipmentFailed $event): void
    {
        $shipment = $this->shipmentRepository->first(['id', $event->shipmentId]);

        if (! $shipment instanceof Shipment) {
            throw new ModelNotFoundException("Shipment with ID {$event->shipmentId} not found.");
        }

        $responsePayload = $event->providerPayload ?? [];

        if ($event->reason !== null) {
            $responsePayload['reason'] = $event->reason;
        }

        $this->shipmentRepository->update(['id', $shipment->id], [
            'status' => ShipmentStatus::FAILED->value,
            'response_payload' => $responsePayload === [] ? null : $responsePayload,
        ]);
    }
}
