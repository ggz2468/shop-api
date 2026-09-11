<?php

namespace App\Services;

use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status;
use App\Events\PaymentAuthorized;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Gateways\Payments\EcpayPaymentGateway;
use App\Models\PaymentTransaction;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class EcpayPaymentCallbackService
{
    /**
     * 授權付款類型前綴
     *
     * @var string[]
     */
    private const AUTHORIZED_PAYMENT_TYPE_PREFIXES = [
        'ATM_',
        'CVS_',
        'BARCODE_',
    ];

    /**
     * 建構子
     *
     * @return void
     */
    public function __construct(
        private EcpayPaymentGateway $ecpayPaymentGateway,
        private PaymentTransactionRepository $paymentTransactionRepository,
        private LoggerInterface $logger,
        private ConfigRepository $config,
        private Dispatcher $events,
    ) {}

    /**
     * 處理綠界金流回應資訊
     *
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
                'RtnCode',
                'RtnMsg',
                'TradeDate',
                'TradeNo',
                'TradeAmt',
                'PaymentType',
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

            // 取得交易紀錄
            $paymentTransaction = $this->paymentTransactionRepository->first([
                ['provider', Provider::ECPAY->value],
                ['merchant_trade_no', $payload['MerchantTradeNo']],
            ]);

            // 檢查交易紀錄是否存在
            if (! $paymentTransaction instanceof PaymentTransaction) {
                throw new RuntimeException('0|Payment transaction not found', 404);
            }

            // 檢查交易紀錄狀態是否為「已取消」、「已退款」其中一種
            if (in_array((int) $paymentTransaction->status, [
                Status::CANCELED->value,
                Status::REFUNDED->value,
            ], true)) {
                throw new RuntimeException('0|Invalid payment transaction status', 400);
            }

            $merchantId = (string) $this->config->get('services.ecpay.merchant_id');

            // 檢查是否有設定 Ecpay merchant id
            if ($merchantId === '') {
                throw new RuntimeException('Ecpay merchant id is not configured.');
            }

            // 檢查 MerchantID 是否正確
            if ($payload['MerchantID'] !== $merchantId) {
                throw new RuntimeException('0|Invalid MerchantID', 400);
            }

            // 檢查交易金額格式是否正確
            if (! ctype_digit($payload['TradeAmt'])) {
                throw new RuntimeException('0|Invalid TradeAmt', 400);
            }

            // 檢查交易金額是否正確
            if ((int) $payload['TradeAmt'] !== (int) $paymentTransaction->amount) {
                throw new RuntimeException('0|Payment amount mismatch', 400);
            }

            // 檢查交易紀錄狀態是否為「已付款」
            if ((int) $paymentTransaction->status === Status::PAID->value) {
                if ($payload['RtnCode'] !== '1') {
                    throw new RuntimeException('0|Payment callback status conflict', 400);
                }

                return [
                    'status' => 200,
                    'content' => '1|OK',
                ];
            }

            $isSuccessfulCallback = $payload['RtnCode'] === '1';
            $isAuthorizedCallback = $this->isAuthorizedCallback($payload);

            // 檢查交易紀錄狀態是否為「已授權」
            if ((int) $paymentTransaction->status === Status::AUTHORIZED->value) {
                // 已授權狀態下，只接受「授權重送」或「付款成功」。
                // 其他狀態都是衝突。
                if (! $isAuthorizedCallback && ! $isSuccessfulCallback) {
                    throw new RuntimeException('0|Payment callback status conflict', 400);
                }

                if ($isAuthorizedCallback) {
                    return [
                        'status' => 200,
                        'content' => '1|OK',
                    ];
                }
            }

            // 檢查交易紀錄狀態是否為「付款失敗」
            if ((int) $paymentTransaction->status === Status::FAILED->value) {
                if ($isSuccessfulCallback) {
                    throw new RuntimeException('0|Payment callback status conflict', 400);
                }

                if ($isAuthorizedCallback) {
                    throw new RuntimeException('0|Payment callback status conflict', 400);
                }

                return [
                    'status' => 200,
                    'content' => '1|OK',
                ];
            }

            // 依據 RtnCode 內容決定應該 dispatch 哪個 event
            $this->events->dispatch(
                match (true) {
                    $isSuccessfulCallback => new PaymentSucceeded($paymentTransaction->id, $payload),
                    $isAuthorizedCallback => new PaymentAuthorized($paymentTransaction->id, $payload),
                    default => new PaymentFailed($paymentTransaction->id, $payload['RtnMsg'], $payload),
                }
            );

            return [
                'status' => 200,
                'content' => '1|OK',
            ];
        } catch (Throwable $e) {
            $code = (int) $e->getCode();
            if (in_array($code, [400, 404], true)) {
                $this->logger->warning('Ecpay payment callback client error: '.$e->getMessage(), [
                    'payload' => $payload,
                    'exception' => $e,
                ]);

                return [
                    'status' => $code,
                    'content' => $e->getMessage(),
                ];
            }

            $this->logger->error('Ecpay payment callback error: '.$e->getMessage(), [
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
            || preg_match('/^[A-F0-9]{64}$/', $receivedCheckMacValue) !== 1
        ) {
            return false;
        }

        $expectedCheckMacValue = $this->ecpayPaymentGateway->makeCheckMacValue($payload);

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
    private function isAuthorizedCallback(array $payload): bool
    {
        if (($payload['RtnCode'] ?? null) !== '2') {
            return false;
        }

        $paymentType = (string) ($payload['PaymentType'] ?? '');

        foreach (self::AUTHORIZED_PAYMENT_TYPE_PREFIXES as $prefix) {
            if (str_starts_with($paymentType, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
