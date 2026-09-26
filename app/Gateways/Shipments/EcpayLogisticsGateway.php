<?php

namespace App\Gateways\Shipments;

use App\Contracts\ShipmentGateway;
use App\Enums\Shipment\ShippingMethod;
use App\Enums\Shipment\StoreType;
use App\Models\Shipment;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

class EcpayLogisticsGateway implements ShipmentGateway
{
    /**
     * 建構子
     *
     * @return void
     */
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * 建立呼叫綠界物流 API 時所需的請求資訊
     *
     * @return array{
     *     action: string,
     *     method: string,
     *     params: array{
     *         MerchantID: string,
     *         MerchantTradeNo: string,
     *         MerchantTradeDate: string,
     *         LogisticsType: string,
     *         LogisticsSubType: string,
     *         GoodsName: string,
     *         GoodsAmount: int,
     *         CollectionAmount: int,
     *         IsCollection: string,
     *         SenderName: string,
     *         SenderCellPhone: string,
     *         ReceiverName: string,
     *         ReceiverCellPhone: string,
     *         ServerReplyURL: string,
     *         SenderZipCode?: string,
     *         SenderAddress?: string,
     *         ReceiverZipCode?: string,
     *         ReceiverAddress?: string,
     *         ReceiverStoreID?: string,
     *         CheckMacValue: string,
     *     }
     * }
     *
     * @throws \RuntimeException
     */
    public function buildShipmentRequest(Shipment $shipment): array
    {
        $shippingMethod = ShippingMethod::from($shipment->shipping_method);

        $this->validateShipment($shipment, $shippingMethod);

        $shipment->loadMissing('order');
        $params = [
            'MerchantID' => $this->requiredConfigString('services.ecpay_logistics.merchant_id'),
            'MerchantTradeNo' => $shipment->order->number,
            'MerchantTradeDate' => $shipment->created_at?->format('Y/m/d H:i:s') ?? now()->format('Y/m/d H:i:s'),
            'LogisticsType' => $this->resolveLogisticsType($shipment),
            'LogisticsSubType' => $this->resolveLogisticsSubType($shipment),
            'GoodsName' => $shipment->order->number,
            'GoodsAmount' => $shipment->order->total_amount,
            'CollectionAmount' => 0,
            'IsCollection' => 'N',
            'SenderName' => $this->requiredConfigString('services.ecpay_logistics.sender_name'),
            'SenderCellPhone' => $this->requiredConfigString('services.ecpay_logistics.sender_cell_phone'),
            'ReceiverName' => $this->requiredShipmentString($shipment->recipient_name, 'Shipment recipient name is not configured.'),
            'ReceiverCellPhone' => $this->requiredShipmentString($shipment->recipient_phone, 'Shipment recipient phone is not configured.'),
            'ServerReplyURL' => $this->requiredConfigString('services.ecpay_logistics.create_server_reply_url'),
        ];
        $params = array_merge($params, match ($this->resolveLogisticsType($shipment)) {
            'Home' => [
                'SenderZipCode' => $this->requiredConfigString('services.ecpay_logistics.sender_zip_code'),
                'SenderAddress' => $this->requiredConfigString('services.ecpay_logistics.sender_address'),
                'ReceiverZipCode' => $this->requiredShipmentString($shipment->recipient_zip_code, 'Shipment recipient zip code is required for home delivery.'),
                'ReceiverAddress' => $this->requiredShipmentString($shipment->recipient_address, 'Shipment recipient address is required for home delivery.'),
            ],
            'CVS' => [
                'ReceiverStoreID' => $this->requiredShipmentString($shipment->store_code, 'Shipment store code is required for convenience store delivery.'),
            ],
        });
        $params['CheckMacValue'] = $this->makeCheckMacValue($params);

        return [
            'action' => $this->requiredConfigString('services.ecpay_logistics.create_action_url'),
            'method' => 'POST',
            'params' => $params,
        ];
    }

    private function resolveLogisticsType(Shipment $shipment): string
    {
        return match (ShippingMethod::from($shipment->shipping_method)) {
            ShippingMethod::HOME_DELIVERY => 'Home',
            ShippingMethod::CONVENIENCE_STORE => 'CVS',
        };
    }

    private function resolveLogisticsSubType(Shipment $shipment): string
    {
        return match (ShippingMethod::from($shipment->shipping_method)) {
            ShippingMethod::HOME_DELIVERY => $this->resolveHomeLogisticsSubType(),
            ShippingMethod::CONVENIENCE_STORE => $this->resolveCvsLogisticsSubType($shipment),
        };
    }

    private function resolveHomeLogisticsSubType(): string
    {
        return $this->requiredConfigString('services.ecpay_logistics.home_logistics_sub_type');
    }

    private function resolveCvsLogisticsSubType(Shipment $shipment): string
    {
        $storeType = $this->requiredShipmentString($shipment->store_type, 'Shipment store type is required for convenience store delivery.');

        return match (StoreType::from($storeType)) {
            StoreType::UNIMART => 'UNIMARTC2C',
            StoreType::FAMI => 'FAMIC2C',
            StoreType::HILIFE => 'HILIFEC2C',
        };
    }

    private function validateShipment(Shipment $shipment, ShippingMethod $shippingMethod): void
    {
        $this->requiredShipmentString($shipment->recipient_name, 'Shipment recipient name is not configured.');
        $this->requiredShipmentString($shipment->recipient_phone, 'Shipment recipient phone is not configured.');

        match ($shippingMethod) {
            ShippingMethod::HOME_DELIVERY => $this->validateHomeDeliveryShipment($shipment),
            ShippingMethod::CONVENIENCE_STORE => $this->validateConvenienceStoreShipment($shipment),
        };
    }

    private function validateHomeDeliveryShipment(Shipment $shipment): void
    {
        $this->requiredShipmentString($shipment->recipient_zip_code, 'Shipment recipient zip code is required for home delivery.');
        $this->requiredShipmentString($shipment->recipient_address, 'Shipment recipient address is required for home delivery.');
    }

    private function validateConvenienceStoreShipment(Shipment $shipment): void
    {
        $this->requiredShipmentString($shipment->store_code, 'Shipment store code is required for convenience store delivery.');
        $this->requiredShipmentString($shipment->store_type, 'Shipment store type is required for convenience store delivery.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function makeCheckMacValue(array $payload): string
    {
        unset($payload['CheckMacValue']);

        uksort($payload, 'strcasecmp');

        $encoded = 'HashKey='.$this->requiredConfigString('services.ecpay_logistics.hash_key')
            .'&'.urldecode(http_build_query($payload))
            .'&HashIV='.$this->requiredConfigString('services.ecpay_logistics.hash_iv');

        $encoded = strtolower(urlencode($encoded));
        $encoded = str_replace(
            ['%2d', '%5f', '%2e', '%21', '%2a', '%28', '%29'],
            ['-', '_', '.', '!', '*', '(', ')'],
            $encoded,
        );

        return strtoupper(md5($encoded));
    }

    private function requiredConfigString(string $key): string
    {
        $value = trim((string) $this->config->get($key, ''));

        if ($value === '') {
            throw new RuntimeException("{$key} is not configured.");
        }

        return $value;
    }

    private function requiredShipmentString(?string $value, string $message): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new RuntimeException($message);
        }

        return $value;
    }
}
