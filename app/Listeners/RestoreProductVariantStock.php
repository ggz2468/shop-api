<?php

namespace App\Listeners;

use App\Enums\Order\Status as OrderStatus;
use App\Events\OrderCanceled;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;

class RestoreProductVariantStock
{
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

            // Match checkout's product lock order, including orders whose details were inserted in reverse.
            foreach ($order->orderDetails->sortBy('product_variant_id')->groupBy('product_variant_id') as $variantId => $details) {
                if (ProductVariant::query()
                    ->where('id', $variantId)
                    ->increment('stock_quantity', (int) $details->sum('quantity')) !== 1) {
                    throw new RuntimeException("Failed to restore stock for product variant with ID {$variantId}.");
                }
            }

            $order->update(['status' => OrderStatus::CANCELED->value]);
        });
    }
}
