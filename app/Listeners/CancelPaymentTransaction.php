<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Repositories\PaymentTransactionRepository;

class CancelPaymentTransaction
{
    public function __construct(
        private PaymentTransactionRepository $paymentTransactionRepository,
    ) {}

    public function handle(OrderCanceled $event): void
    {
        $this->paymentTransactionRepository->cancelPendingForOrder($event->orderId);
    }
}
