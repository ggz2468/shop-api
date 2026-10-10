<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Events\PaymentInitiated;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Repositories\PaymentTransactionRepository;
use App\Services\PaymentTransactionService;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CreatePaymentTransaction
{
    /**
     * @return void
     */
    public function __construct(
        private PaymentTransactionService $paymentTransactionService,
        private PaymentTransactionRepository $paymentTransactionRepository,
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    public function handle(OrderCreated $event): void
    {
        $this->db->transaction(function () use ($event): void {
            $order = Order::query()
                ->lockForUpdate()
                ->find($event->orderId);

            if (! $order instanceof Order) {
                throw new ModelNotFoundException("Order with ID {$event->orderId} not found.");
            }

            $existingPaymentTransaction = $this->paymentTransactionRepository->first(['order_id', $order->id]);

            if ($existingPaymentTransaction instanceof PaymentTransaction) {
                return;
            }

            $paymentTransaction = $this->paymentTransactionService->create($order);

            $this->events->dispatch(new PaymentInitiated($paymentTransaction->id));
        });
    }
}
