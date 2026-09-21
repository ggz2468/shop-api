<?php

namespace App\Gateways\Shipments;

use App\Contracts\ShipmentGateway;
use App\Enums\Shipment\Provider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class ShipmentGatewayManager
{
    /**
     * @return void
     */
    public function __construct(
        private Container $container,
        private ConfigRepository $config,
    ) {}

    public function driver(int|Provider $provider): ShipmentGateway
    {
        $provider = $provider instanceof Provider ? $provider : Provider::tryFrom($provider);
        $gatewayClass = $provider instanceof Provider
            ? $this->config->get("services.shipment.gateways.{$provider->value}")
            : null;

        if (! is_string($gatewayClass)) {
            throw new InvalidArgumentException('Unsupported shipment provider.');
        }

        $gateway = $this->container->make($gatewayClass);

        if (! $gateway instanceof ShipmentGateway) {
            throw new InvalidArgumentException('Shipment gateway must implement ShipmentGateway.');
        }

        return $gateway;
    }
}
