<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentRequested
{
    use Dispatchable, SerializesModels;

    /**
     * @return void
     */
    public function __construct(
        public int $shipmentId,
    ) {}
}
