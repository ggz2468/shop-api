<?php

namespace App\Repositories;

use App\Enums\PaymentTransaction\Status;
use App\Models\Order;
use App\Models\PaymentTransaction;

class PaymentTransactionRepository extends Repository
{
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
