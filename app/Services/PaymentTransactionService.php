<?php

namespace App\Services;

use App\Enums\Order\PaymentMethod as OrderPaymentMethod;
use App\Enums\PaymentTransaction\PaymentMethod as PaymentTransactionPaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use RuntimeException;

class PaymentTransactionService
{
    private const int MAX_MERCHANT_TRADE_NO_ATTEMPTS = 10;

    public function __construct(
        private PaymentTransactionRepository $paymentTransactionRepository,
        private ConfigRepository $config,
    ) {}

    public function create(Order $order): PaymentTransaction
    {
        $provider = $this->resolveDefaultProvider();

        for ($attempt = 0; $attempt < self::MAX_MERCHANT_TRADE_NO_ATTEMPTS; $attempt++) {
            $merchantTradeNo = $this->makeMerchantTradeNo();

            if (! $this->paymentTransactionRepository->doesNotExist([
                ['provider', $provider->value],
                ['merchant_trade_no', $merchantTradeNo],
            ])) {
                continue;
            }

            try {
                return $this->paymentTransactionRepository->create([
                    'order_id' => $order->id,
                    'provider' => $provider->value,
                    'merchant_trade_no' => $merchantTradeNo,
                    'amount' => $order->total_amount,
                    'currency' => 'TWD',
                    'status' => Status::PENDING->value,
                    'payment_method' => $this->resolvePaymentMethod($order)->value,
                    'request_payload' => null,
                    'checkout_payload' => null,
                    'response_payload' => null,
                ]);
            } catch (QueryException $e) {
                if (! $this->isMerchantTradeNoUniqueConstraintViolation($e)) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Unable to generate unique merchant trade no.');
    }

    private function resolveDefaultProvider(): Provider
    {
        $provider = Provider::tryFrom((int) $this->config->get('services.payment.default_provider'));

        if (! $provider instanceof Provider) {
            throw new RuntimeException('Default payment provider is not supported.');
        }

        return $provider;
    }

    private function resolvePaymentMethod(Order $order): PaymentTransactionPaymentMethod
    {
        return match (OrderPaymentMethod::from($order->payment_method)) {
            OrderPaymentMethod::CREDIT_CARD => PaymentTransactionPaymentMethod::CREDIT_CARD,
            OrderPaymentMethod::ATM => PaymentTransactionPaymentMethod::ATM,
            OrderPaymentMethod::CVS => PaymentTransactionPaymentMethod::CVS,
            OrderPaymentMethod::BARCODE => PaymentTransactionPaymentMethod::BARCODE,
        };
    }

    private function makeMerchantTradeNo(): string
    {
        return sprintf(
            'PAY%s%s',
            now()->format('Ymd'),
            $this->randomUppercaseAlphanumeric(6),
        );
    }

    private function isMerchantTradeNoUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();
        $driverErrorCode = $exception->errorInfo[1] ?? null;

        return (string) $sqlState === '23505'
            || ((string) $sqlState === '23000' && (int) $driverErrorCode === 1062);
    }

    private function randomUppercaseAlphanumeric(int $length): string
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $value = '';

        for ($index = 0; $index < $length; $index++) {
            $value .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $value;
    }
}
