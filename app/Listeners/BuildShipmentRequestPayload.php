<?php

namespace App\Listeners;

use App\Events\ShipmentRequested;
use App\Events\ShipmentRequestPayloadBuilt;
use App\Gateways\Shipments\ShipmentGatewayManager;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

class BuildShipmentRequestPayload
{
    /**
     * @return void
     */
    public function __construct(
        private ShipmentRepository $shipmentRepository,
        private ShipmentGatewayManager $shipmentGatewayManager,
        private LoggerInterface $logger,
        private Dispatcher $events,
    ) {}

    public function handle(ShipmentRequested $event): void
    {
        $shipment = $this->shipmentRepository->first(['id', $event->shipmentId]);

        if (! $shipment instanceof Shipment) {
            throw new ModelNotFoundException("Shipment with ID {$event->shipmentId} not found.");
        }

        $shipmentRequest = $this->shipmentGatewayManager
            ->driver($shipment->provider)
            ->buildShipmentRequest($shipment);

        $this->shipmentRepository->update(['id', $shipment->id], [
            'request_payload' => $shipmentRequest['params'],
            'checkout_payload' => [
                'action' => $shipmentRequest['action'],
                'method' => $shipmentRequest['method'],
            ],
        ]);

        $this->logger->info('Shipment request payload is ready.', [
            'shipment_id' => $shipment->id,
            'provider' => $shipment->provider,
        ]);

        $this->events->dispatch(new ShipmentRequestPayloadBuilt($shipment->id));
    }
}
