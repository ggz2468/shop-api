<?php

namespace App\Services;

use App\Enums\Shipment\Provider;
use App\Enums\ShipmentStoreMapRequest\StoreType;
use App\Models\ShipmentStoreMapRequest;
use App\Repositories\ShipmentStoreMapRequestRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class EcpayShipmentStoreMapCallbackService
{
    /**
     * 建構子
     *
     * @return void
     */
    public function __construct(
        private ShipmentStoreMapRequestRepository $shipmentStoreMapRequestRepository,
        private ConfigRepository $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * 處理綠界電子地圖回應資訊。
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, content?: string, redirect_url?: string}
     */
    public function handle(array $payload): array
    {
        try {
            $payload = $this->normalizePayload($payload);
            $requiredFields = [
                'MerchantID',
                'MerchantTradeNo',
                'LogisticsSubType',
                'CVSStoreID',
                'CVSStoreName',
                'CVSAddress',
                'ExtraData',
            ];

            foreach ($requiredFields as $field) {
                if (! array_key_exists($field, $payload) || $payload[$field] === '') {
                    throw new RuntimeException("0|Missing required field: $field", 400);
                }
            }

            $merchantId = $this->requiredConfigString('services.ecpay_logistics.merchant_id');

            if ($payload['MerchantID'] !== $merchantId) {
                throw new RuntimeException('0|Invalid MerchantID', 400);
            }

            $clientRedirectUrl = $this->requiredConfigString('services.ecpay_logistics.store_map_client_redirect_url');
            $shipmentStoreMapRequest = $this->shipmentStoreMapRequestRepository->first([
                ['provider', Provider::ECPAY_LOGISTICS->value],
                ['merchant_trade_no', $payload['MerchantTradeNo']],
                ['selection_token', $payload['ExtraData']],
            ]);

            if (! $shipmentStoreMapRequest instanceof ShipmentStoreMapRequest) {
                throw new RuntimeException('0|Shipment store map request not found', 404);
            }

            if ($shipmentStoreMapRequest->expires_at !== null && $shipmentStoreMapRequest->expires_at->isPast()) {
                throw new RuntimeException('0|Shipment store map request expired', 400);
            }

            if ($payload['LogisticsSubType'] !== $this->resolveLogisticsSubType($shipmentStoreMapRequest->store_type)) {
                throw new RuntimeException('0|LogisticsSubType mismatch', 400);
            }

            $this->shipmentStoreMapRequestRepository->update(['id', $shipmentStoreMapRequest->id], [
                'response_payload' => $payload,
                'selected_store_code' => $payload['CVSStoreID'],
                'selected_store_name' => $payload['CVSStoreName'],
                'selected_store_address' => $payload['CVSAddress'],
            ]);

            return [
                'status' => 302,
                'redirect_url' => $clientRedirectUrl,
            ];
        } catch (Throwable $e) {
            $code = (int) $e->getCode();

            if (in_array($code, [400, 404], true)) {
                $this->logger->warning('Ecpay shipment store map callback client error: '.$e->getMessage(), [
                    'payload' => $payload,
                    'exception' => $e,
                ]);

                return [
                    'status' => $code,
                    'content' => $e->getMessage(),
                ];
            }

            $this->logger->error('Ecpay shipment store map callback error: '.$e->getMessage(), [
                'payload' => $payload,
                'exception' => $e,
            ]);

            return [
                'status' => 500,
                'content' => '0|Internal Server Error',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        return array_map(
            fn (mixed $value): mixed => match (true) {
                is_string($value) => trim($value),
                $value === null => '',
                default => $value,
            },
            $payload,
        );
    }

    private function resolveLogisticsSubType(StoreType $storeType): string
    {
        return match ($storeType) {
            StoreType::UNIMART => 'UNIMARTC2C',
            StoreType::FAMI => 'FAMIC2C',
            StoreType::HILIFE => 'HILIFEC2C',
        };
    }

    private function requiredConfigString(string $key): string
    {
        $value = trim((string) $this->config->get($key, ''));

        if ($value === '') {
            throw new RuntimeException("{$key} is not configured.");
        }

        return $value;
    }
}
