<?php

namespace App\Listeners;

use App\Enums\Order\PaymentMethod as OrderPaymentMethod;
use App\Enums\PaymentTransaction\PaymentMethod;
use App\Enums\PaymentTransaction\Provider;
use App\Enums\PaymentTransaction\Status;
use App\Events\OrderCreated;
use App\Events\PaymentInitiated;
use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

class CreatePaymentTransaction
{
    /**
     * @return void
     */
    public function __construct(
        private OrderRepository $orderRepository,
        private PaymentTransactionRepository $paymentTransactionRepository,
        private Dispatcher $events,
        private ConfigRepository $config,
    ) {}

    public function handle(OrderCreated $event): void
    {
        $order = $this->orderRepository->first(['id', $event->orderId]);

        if (! $order instanceof Order) {
            throw new ModelNotFoundException("Order with ID {$event->orderId} not found.");
        }

        $existingPaymentTransaction = $this->paymentTransactionRepository->first(['order_id', $order->id]);

        if ($existingPaymentTransaction !== null) {
            return;
        }

        $paymentTransaction = $this->paymentTransactionRepository->create([
            'order_id' => $order->id,
            'provider' => $this->resolveDefaultProvider()->value,
            'merchant_trade_no' => $this->makeMerchantTradeNo($order->number),
            'amount' => $order->total_amount,
            'currency' => 'TWD',
            'status' => Status::PENDING->value,
            'payment_method' => $this->resolvePaymentMethod($order->payment_method)->value,
            'request_payload' => null,
            'checkout_payload' => null,
            'response_payload' => null,
        ]);

        $this->events->dispatch(new PaymentInitiated($paymentTransaction->id));
    }

    private function resolveDefaultProvider(): Provider
    {
        $provider = Provider::tryFrom((int) $this->config->get('services.payment.default_provider'));

        if (! $provider instanceof Provider) {
            throw new RuntimeException('Default payment provider is not supported.');
        }

        return $provider;
    }

    private function resolvePaymentMethod(int $paymentMethod): PaymentMethod
    {
        return match (OrderPaymentMethod::from($paymentMethod)) {
            OrderPaymentMethod::CREDIT_CARD => PaymentMethod::CREDIT_CARD,
            OrderPaymentMethod::ATM => PaymentMethod::ATM,
            OrderPaymentMethod::CVS => PaymentMethod::CVS,
            OrderPaymentMethod::BARCODE => PaymentMethod::BARCODE,
        };
    }

    private function makeMerchantTradeNo(string $orderNumber): string
    {
        $orderNumberSuffix = str_starts_with($orderNumber, 'ORD')
            ? substr($orderNumber, 3)
            : $orderNumber;

        return substr('PAY'.$orderNumberSuffix, 0, 64);
    }
}
