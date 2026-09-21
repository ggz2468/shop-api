<?php

namespace App\Contracts;

use App\Models\Shipment;

interface ShipmentGateway
{
    /**
     * 建立呼叫第三方物流 API 時所需的請求資訊
     *
     * @return array<string, mixed>
     */
    public function buildShipmentRequest(Shipment $shipment): array;
}
