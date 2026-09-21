<?php

namespace App\Listeners;

use App\Events\ShipmentCreated;
use App\Events\ShipmentFailed;
use App\Events\ShipmentRequestPayloadBuilt;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SubmitShipmentRequest implements ShouldQueue
{
    /**
     * @return void
     */
    public function __construct(
        private HttpFactory $http,
        private ShipmentRepository $shipmentRepository,
        private LoggerInterface $logger,
        private Dispatcher $events,
    ) {}

    public function handle(ShipmentRequestPayloadBuilt $event): void
    {
        $shipment = $this->shipmentRepository->first(['id', $event->shipmentId]);

        if (! $shipment instanceof Shipment) {
            throw new ModelNotFoundException("Shipment not found with ID {$event->shipmentId}");
        }

        if (! $this->isShipmentRequestPayloadReady($shipment)) {
            throw new RuntimeException('Shipment request payload is not ready.');
        }

        if (! $this->isShipmentCheckoutPayloadReady($shipment)) {
            throw new RuntimeException('Shipment checkout payload is not ready.');
        }

        $checkoutPayload = $shipment->checkout_payload;
        $requestMethod = trim(strtolower($checkoutPayload['method']));
        $requestUrl = trim($checkoutPayload['action']);
        $response = $this->http
            ->asForm()
            ->$requestMethod($requestUrl, $shipment->request_payload);

        if (! $response->ok()) {
            $this->logger->warning('Shipment request HTTP error.', [
                'shipment_id' => $shipment->id,
            ]);
            $this->events->dispatch(new ShipmentFailed($shipment->id, "HTTP {$response->status()}", [
                'error_type' => 'http_error',
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]));

            return;
        }

        parse_str($response->body(), $providerPayload);
        $rtnCode = $providerPayload['RtnCode'] ?? null;

        if ($rtnCode === null) {
            $providerPayload = [
                'error_type' => 'invalid_provider_response',
                'body' => $response->body(),
                'parsed_payload' => $providerPayload,
            ];

            $this->logger->warning('Shipment request returned invalid provider response.', [
                'shipment_id' => $shipment->id,
                'provider_payload' => $providerPayload,
            ]);
            $this->events->dispatch(new ShipmentFailed($shipment->id, 'Invalid shipment provider response.', $providerPayload));

            return;
        }

        if ($rtnCode !== '1') {
            $this->logger->warning('Shipment request is rejected by provider.', [
                'shipment_id' => $shipment->id,
                'provider_payload' => $providerPayload,
            ]);
            $this->events->dispatch(new ShipmentFailed($shipment->id, $providerPayload['RtnMsg'] ?? '', $providerPayload));

            return;
        }

        $this->logger->info('Shipment request is submitted.', [
            'shipment_id' => $shipment->id,
            'provider_payload' => $providerPayload,
        ]);

        $this->events->dispatch(new ShipmentCreated($shipment->id, $providerPayload));
    }

    private function isShipmentRequestPayloadReady(Shipment $shipment): bool
    {
        return ! empty($shipment->request_payload);
    }

    private function isShipmentCheckoutPayloadReady(Shipment $shipment): bool
    {
        return ! empty($shipment->checkout_payload);
    }
}
