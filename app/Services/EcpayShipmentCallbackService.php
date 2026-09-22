<?php

namespace App\Services;

use App\Enums\Shipment\Provider;
use App\Enums\Shipment\Status as ShipmentStatus;
use App\Events\ShipmentDelivered;
use App\Events\ShipmentFailed;
use App\Events\ShipmentShipped;
use App\Gateways\Shipments\EcpayLogisticsGateway;
use App\Models\Shipment;
use App\Repositories\ShipmentRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class EcpayShipmentCallbackService
{
    public function __construct(
        private ShipmentRepository $shipmentRepository,
        private EcpayLogisticsGateway $ecpayLogisticsGateway,
        private Dispatcher $events,
        private ConfigRepository $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, content: string}
     */
    public function handle(array $payload): array
    {
        try {
            $payload = $this->normalizePayload($payload);

            // 必要欄位
            $requiredFields = [
                'MerchantID',
                'MerchantTradeNo',
                'AllPayLogisticsID',
                'LogisticsType',
                'LogisticsSubType',
                'LogisticsStatus',
                'LogisticsStatusName',
                'GoodsAmount',
                'UpdateStatusDate',
                'RtnCode',
                'RtnMsg',
                'CheckMacValue',
            ];

            // 檢查必要欄位是否存在
            foreach ($requiredFields as $field) {
                if (! array_key_exists($field, $payload) || $payload[$field] === '') {
                    throw new RuntimeException("0|Missing required field: $field", 400);
                }
            }

            // 檢查 CheckMacValue 是否有效
            if (! $this->isValidCheckMacValue($payload)) {
                throw new RuntimeException('0|Invalid CheckMacValue', 400);
            }

            unset($payload['CheckMacValue']);

            // 檢查 MerchantID 是否符合設定
            if ((string) $payload['MerchantID'] !== (string) $this->config->get('services.ecpay_logistics.merchant_id')) {
                throw new RuntimeException('0|Invalid MerchantID', 400);
            }

            // 取得物流單
            $shipment = $this->shipmentRepository->first([
                ['provider', Provider::ECPAY_LOGISTICS->value],
                ['tracking_number', $payload['AllPayLogisticsID']],
            ]);

            // 檢查物流單是否存在
            if (! $shipment instanceof Shipment) {
                throw new RuntimeException('0|Shipment not found', 404);
            }

            $event = $this->resolveEvent($shipment, $payload);

            // 檢查 LogisticsStatus 是否為預期中的值
            if ($event === null) {
                throw new RuntimeException('0|Unsupported LogisticsStatus', 400);
            }

            // 如果物流單狀態為「已送達」，則直接確認且不觸發任何事件
            if ($shipment->status === ShipmentStatus::DELIVERED->value) {
                return [
                    'status' => 200,
                    'content' => '1|OK',
                ];
            }

            $this->events->dispatch($event);

            return [
                'status' => 200,
                'content' => '1|OK',
            ];
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            if (in_array($code, [400, 404], true)) {
                $this->logger->warning('Ecpay shipment callback client error: '.$e->getMessage(), [
                    'payload' => $payload,
                    'exception' => $e,
                ]);

                return [
                    'status' => $code,
                    'content' => $e->getMessage(),
                ];
            }

            $this->logger->error('Ecpay shipment callback error: '.$e->getMessage(), [
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
     * 檢查 CheckMacValue 是否有效
     *
     * @param  array<string, mixed>  $payload
     */
    private function isValidCheckMacValue(array $payload): bool
    {
        $receivedCheckMacValue = $payload['CheckMacValue'] ?? null;

        if (
            ! is_string($receivedCheckMacValue)
            || preg_match('/^[A-F0-9]{32}$/', $receivedCheckMacValue) !== 1
        ) {
            return false;
        }

        $expectedCheckMacValue = $this->ecpayLogisticsGateway->makeCheckMacValue($payload);

        return hash_equals($expectedCheckMacValue, $receivedCheckMacValue);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveEvent(Shipment $shipment, array $payload): ShipmentShipped|ShipmentDelivered|ShipmentFailed|null
    {
        $logisticsStatus = $payload['LogisticsStatus'] ?? null;

        return match ($logisticsStatus) {
            '300' => new ShipmentShipped($shipment->id, $payload),
            '2067' => new ShipmentDelivered($shipment->id, $payload),
            '2001' => new ShipmentFailed($shipment->id, $payload['RtnMsg'] ?? '', $payload),
            default => null,
        };
    }
}
