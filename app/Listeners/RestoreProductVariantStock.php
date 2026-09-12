<?php

namespace App\Listeners;

use App\Enums\Order\Status as OrderStatus;
use App\Events\OrderCanceled;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RestoreProductVariantStock
{
    /**
     * @return void
     */
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function handle(OrderCanceled $event): void
    {
        $this->db->transaction(function () use ($event): void {
            $order = Order::query()
                ->with('orderDetails')
                ->lockForUpdate()
                ->find($event->orderId);

            if (! $order instanceof Order) {
                throw new ModelNotFoundException("Order with ID {$event->orderId} not found.");
            }

            if ($order->status === OrderStatus::CANCELED->value) {
                return;
            }

            foreach ($order->orderDetails as $orderDetail) {
                ProductVariant::query()
                    ->where('id', $orderDetail->product_variant_id)
                    ->increment('stock_quantity', $orderDetail->quantity);
            }

            $order->update(['status' => OrderStatus::CANCELED->value]);
        });
    }
}
