<?php

namespace Tests\Unit;

use App\Enums\Order\Status as OrderStatus;
use App\Events\OrderCanceled;
use App\Listeners\RestoreProductVariantStock;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestoreProductVariantStockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * OrderCanceled: 相同商品出現在多筆明細時，應加總數量並只更新一次庫存，保留原始明細。
     */
    public function test_handle_aggregates_duplicate_variant_details_into_one_stock_update(): void
    {
        $order = Order::factory()->create();
        $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
        OrderDetail::factory()->for($order)->forProductVariant($variant, 2)->create();
        OrderDetail::factory()->for($order)->forProductVariant($variant, 3)->create();
        $detailsBefore = $order->orderDetails()->orderBy('id')->get()->toArray();
        $stockUpdates = 0;
        DB::listen(function (QueryExecuted $query) use (&$stockUpdates): void {
            if (preg_match('/^update [`"]?product_variants[`"]?\s/i', $query->sql)) {
                $stockUpdates++;
            }
        });

        app(RestoreProductVariantStock::class)->handle(new OrderCanceled($order->id));

        $this->assertSame(15, $variant->refresh()->stock_quantity);
        $this->assertSame(1, $stockUpdates);
        $this->assertSame(OrderStatus::CANCELED->value, $order->refresh()->status);
        $this->assertSame($detailsBefore, $order->orderDetails()->orderBy('id')->get()->toArray());
    }

    /**
     * OrderCanceled: 找不到訂單時應拋出例外，不得修改其他商品庫存。
     */
    public function test_handle_throws_model_not_found_exception_when_order_is_missing(): void
    {
        $variant = ProductVariant::factory()->create(['stock_quantity' => 10]);
        $this->expectException(ModelNotFoundException::class);

        try {
            app(RestoreProductVariantStock::class)->handle(new OrderCanceled(999999));
        } finally {
            $this->assertSame(10, $variant->refresh()->stock_quantity);
        }
    }
}
