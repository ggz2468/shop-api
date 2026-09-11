<?php

namespace App\Services;

use App\Enums\ShipmentStoreMapRequest\StoreType;
use App\Models\ShipmentStoreMapRequest;
use App\Repositories\ShipmentStoreMapRequestRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class ShipmentStoreMapRequestService
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
     * 建立超商電子地圖選擇請求。
     *
     * @return array<string, mixed>
     */
    public function create(int $memberId, string $storeType, ?int $device = null): array
    {
        try {
            $merchantId = $this->requiredConfigString('services.ecpay_logistics.merchant_id');
            $storeMapActionUrl = $this->requiredConfigString('services.ecpay_logistics.store_map_action_url');
            $storeMapServerReplyUrl = $this->requiredConfigString('services.ecpay_logistics.store_map_server_reply_url');
            $selectionToken = $this->makeSelectionToken();
            $merchantTradeNo = $this->makeMerchantTradeNo();
            $expiresAt = now()->addMinutes($this->storeMapRequestTtlMinutes());
            $requestPayload = [
                'MerchantID' => $merchantId,
                'MerchantTradeNo' => $merchantTradeNo,
                'LogisticsType' => 'CVS',
                'LogisticsSubType' => $this->resolveLogisticsSubType($storeType),
                'IsCollection' => 'N',
                'ServerReplyURL' => $storeMapServerReplyUrl,
                'ExtraData' => $selectionToken,
                'Device' => $device ?? 0,
            ];
            $requestPayload['CheckMacValue'] = $this->makeCheckMacValue($requestPayload);

            $shipmentStoreMapRequest = $this->shipmentStoreMapRequestRepository->create([
                'member_id' => $memberId,
                'provider' => (int) $this->config->get('services.shipment.default_provider'),
                'store_type' => $storeType,
                'merchant_trade_no' => $merchantTradeNo,
                'selection_token' => $selectionToken,
                'request_payload' => $requestPayload,
                'checkout_payload' => [
                    'action' => $storeMapActionUrl,
                    'method' => 'POST',
                ],
                'response_payload' => null,
                'selected_store_code' => null,
                'selected_store_name' => null,
                'selected_store_address' => null,
                'expires_at' => $expiresAt,
            ]);

            return [
                'status' => 201,
                'data' => [
                    'id' => $shipmentStoreMapRequest->id,
                    'selection_token' => $shipmentStoreMapRequest->selection_token,
                    'provider' => $shipmentStoreMapRequest->provider,
                    'store_type' => $shipmentStoreMapRequest->store_type,
                    'ready' => true,
                    'expires_at' => $shipmentStoreMapRequest->expires_at,
                    'checkout_payload' => $shipmentStoreMapRequest->checkout_payload,
                    'request_payload' => $shipmentStoreMapRequest->request_payload,
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'member_id' => $memberId,
                'store_type' => $storeType,
                'device' => $device,
                'exception' => $e,
            ]);

            return [
                'status' => 500,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 查詢超商電子地圖選擇結果。
     *
     * @return array<string, mixed>
     */
    public function getSelectionResult(int $memberId, string $selectionToken): array
    {
        try {
            $shipmentStoreMapRequest = $this->shipmentStoreMapRequestRepository->first([
                ['member_id', $memberId],
                ['selection_token', $selectionToken],
            ]);

            if (! $shipmentStoreMapRequest instanceof ShipmentStoreMapRequest) {
                return [
                    'status' => 404,
                    'message' => '找不到超商電子地圖選擇請求。',
                ];
            }

            $hasSelectedStore = $this->hasSelectedStore($shipmentStoreMapRequest);

            if (! $hasSelectedStore && $shipmentStoreMapRequest->expires_at !== null && $shipmentStoreMapRequest->expires_at->isPast()) {
                return [
                    'status' => 410,
                    'message' => '超商電子地圖選擇請求已過期。',
                ];
            }

            return [
                'status' => 200,
                'data' => [
                    'selection_token' => $shipmentStoreMapRequest->selection_token,
                    'store_type' => $shipmentStoreMapRequest->store_type->value,
                    'selected' => $hasSelectedStore,
                    'store' => $hasSelectedStore ? [
                        'code' => $shipmentStoreMapRequest->selected_store_code,
                        'name' => $shipmentStoreMapRequest->selected_store_name,
                        'address' => $shipmentStoreMapRequest->selected_store_address,
                    ] : null,
                    'expires_at' => $shipmentStoreMapRequest->expires_at,
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), [
                'member_id' => $memberId,
                'selection_token' => $selectionToken,
                'exception' => $e,
            ]);

            return [
                'status' => 500,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * 產生 shipment_store_map_requests.selection_token
     */
    private function makeSelectionToken(): string
    {
        do {
            $selectionToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } while ($this->shipmentStoreMapRequestRepository->exists(['selection_token', $selectionToken]));

        return $selectionToken;
    }

    /**
     * 產生 shipment_store_map_requests.merchant_trade_no
     */
    private function makeMerchantTradeNo(): string
    {
        do {
            $randomString = substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 6);
            $merchantTradeNo = sprintf('SMR%s%s', now()->format('Ymd'), $randomString);
        } while ($this->shipmentStoreMapRequestRepository->exists(['merchant_trade_no', $merchantTradeNo]));

        return $merchantTradeNo;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function makeCheckMacValue(array $payload): string
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

    private function resolveLogisticsSubType(string $storeType): string
    {
        return match ($storeType) {
            StoreType::UNIMART->value => 'UNIMARTC2C',
            StoreType::FAMI->value => 'FAMIC2C',
            StoreType::HILIFE->value => 'HILIFEC2C',
            StoreType::OKMART->value => 'OKMARTC2C',
        };
    }

    private function storeMapRequestTtlMinutes(): int
    {
        $ttlMinutes = (int) $this->config->get('services.ecpay_logistics.store_map_request_ttl_minutes', 30);

        return max(1, $ttlMinutes);
    }

    private function hasSelectedStore(ShipmentStoreMapRequest $shipmentStoreMapRequest): bool
    {
        return $shipmentStoreMapRequest->selected_store_code !== null
            && $shipmentStoreMapRequest->selected_store_name !== null
            && $shipmentStoreMapRequest->selected_store_address !== null;
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
