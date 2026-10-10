<?php

namespace App\Services;

use App\Enums\Order\PaymentStatus as OrderPaymentStatus;
use App\Enums\Order\Status as OrderStatus;
use App\Enums\PaymentTransaction\Provider;
use App\Events\PaymentInitiated;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentTransactionRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class OrderPaymentTransactionService
{
    public function __construct(
        private PaymentTransactionService $paymentTransactionService,
        private OrderRepository $orderRepository,
        private PaymentTransactionRepository $paymentTransactionRepository,
        private ConnectionInterface $db,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{status: int, message: string, data?: array<string, mixed>}
     */
    public function createForOrder(int $memberId, Order $order): array
    {
        try {
            $paymentTransaction = $this->db->transaction(function () use ($memberId, $order): PaymentTransaction {
                $lockedOrder = Order::query()
                    ->lockForUpdate()
                    ->find($order->id);

                if (! ($lockedOrder instanceof Order) || (int) $lockedOrder->member_id !== $memberId) {
                    throw new RuntimeException('找不到付款資料。', 404);
                }

                $activePaymentTransaction = $this->paymentTransactionRepository->getPendingOrAuthorizedByOrder($lockedOrder);

                if ($activePaymentTransaction instanceof PaymentTransaction) {
                    throw new RuntimeException('已有進行中的付款交易。', 409);
                }

                if (! $this->isOrderPayable($lockedOrder)) {
                    throw new RuntimeException('此訂單目前無法建立付款交易。', 409);
                }

                if ((int) $lockedOrder->payment_status === OrderPaymentStatus::FAILED->value) {
                    if ($this->orderRepository->update(['id', $lockedOrder->id], [
                        'payment_status' => OrderPaymentStatus::UNPAID->value,
                    ]) < 1) {
                        throw new RuntimeException('更新訂單付款狀態失敗。', 500);
                    }

                    $lockedOrder->refresh();
                }

                $paymentTransaction = $this->paymentTransactionService->create($lockedOrder);

                $this->events->dispatch(new PaymentInitiated($paymentTransaction->id));

                return $paymentTransaction->refresh();
            });

            return [
                'status' => 201,
                'message' => '付款交易已建立。',
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->number,
                    'payment_transaction_id' => $paymentTransaction->id,
                    'provider' => $paymentTransaction->provider,
                    'provider_name' => strtolower(Provider::tryFrom($paymentTransaction->provider)?->name ?? ''),
                    'amount' => $paymentTransaction->amount,
                    'currency' => $paymentTransaction->currency,
                    'status' => $paymentTransaction->status,
                    'payment_method' => $paymentTransaction->payment_method,
                    'request_payload' => $paymentTransaction->request_payload,
                    'checkout_payload' => $paymentTransaction->checkout_payload,
                ],
            ];
        } catch (Throwable $e) {
            $code = $e->getCode();

            if (in_array($code, [404, 409], true)) {
                $this->logger->warning($e->getMessage(), [
                    'member_id' => $memberId,
                    'order_id' => $order->id,
                    'exception' => $e,
                ]);

                return [
                    'status' => $code,
                    'message' => $e->getMessage(),
                ];
            }

            $this->logger->error('建立付款交易失敗', [
                'member_id' => $memberId,
                'order_id' => $order->id,
                'exception' => $e,
            ]);

            return [
                'status' => 500,
                'message' => '付款交易建立失敗，請稍後再試。',
            ];
        }
    }

    private function isOrderPayable(Order $order): bool
    {
        return (int) $order->status === OrderStatus::STOCKING->value
            && in_array((int) $order->payment_status, [
                OrderPaymentStatus::UNPAID->value,
                OrderPaymentStatus::FAILED->value,
            ], true);
    }
}
