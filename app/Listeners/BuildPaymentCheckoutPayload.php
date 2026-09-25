<?php

namespace App\Listeners;

use App\Events\PaymentInitiated;
use App\Gateways\Payments\PaymentGatewayManager;
use App\Models\PaymentTransaction;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;

class BuildPaymentCheckoutPayload
{
    /**
     * @return void
     */
    public function __construct(
        private PaymentTransactionRepository $paymentTransactionRepository,
        private PaymentGatewayManager $paymentGatewayManager,
        private LoggerInterface $logger,
    ) {}

    public function handle(PaymentInitiated $event): void
    {
        $paymentTransaction = $this->paymentTransactionRepository->first(['id', $event->paymentTransactionId]);

        if (! $paymentTransaction instanceof PaymentTransaction) {
            throw new ModelNotFoundException("Payment transaction with ID {$event->paymentTransactionId} not found.");
        }

        $paymentRequest = $this->paymentGatewayManager
            ->driver($paymentTransaction->provider)
            ->buildPaymentRequest($paymentTransaction);

        $this->paymentTransactionRepository->update(['id', $paymentTransaction->id], [
            'request_payload' => $paymentRequest['params'],
            'checkout_payload' => [
                'action' => $paymentRequest['action'],
                'method' => $paymentRequest['method'],
            ],
        ]);

        $this->logger->info('Payment checkout payload is ready.', [
            'payment_transaction_id' => $paymentTransaction->id,
            'provider' => $paymentTransaction->provider,
        ]);
    }
}
