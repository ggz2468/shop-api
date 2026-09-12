<?php

namespace App\Listeners;

use App\Enums\PaymentTransaction\Status;
use App\Events\OrderCanceled;
use App\Repositories\PaymentTransactionRepository;

class CancelPaymentTransaction
{
    /**
     * @return void
     */
    public function __construct(
        private PaymentTransactionRepository $paymentTransactionRepository,
    ) {}

    public function handle(OrderCanceled $event): void
    {
        $paymentTransactions = $this->paymentTransactionRepository->get(['order_id', $event->orderId]);
        $cancelableStatuses = [
            Status::PENDING->value,
            Status::AUTHORIZED->value,
        ];

        foreach ($paymentTransactions as $paymentTransaction) {
            if (! in_array($paymentTransaction->status, $cancelableStatuses, true)) {
                continue;
            }

            $this->paymentTransactionRepository->update(['id', $paymentTransaction->id], [
                'status' => Status::CANCELED->value,
            ]);
        }
    }
}
