<?php

namespace App\Repositories;

use App\Enums\PaymentTransaction\Status;
use App\Models\Order;
use App\Models\PaymentTransaction;

class PaymentTransactionRepository extends Repository
{
    /**
     * 呼叫端須已鎖定 Order，避免掃描非唯一狀態索引時鎖住其他訂單的 index gap。
     */
    public function cancelPendingForOrder(int $orderId): int
    {
        $ids = PaymentTransaction::query()
            ->where('order_id', $orderId)
            ->where('status', Status::PENDING->value)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return PaymentTransaction::query()
            ->whereKey($ids)
            ->update(['status' => Status::CANCELED->value]);
    }

    /**
     * 取得指定訂單中，狀態為「待建立付款」或「已授權」的付款交易
     */
    public function getPendingOrAuthorizedByOrder(Order $order): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [Status::PENDING->value, Status::AUTHORIZED->value])
            ->first();
    }
}
