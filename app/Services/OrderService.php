<?php

namespace App\Services;

use App\Enums\Order\PaymentStatus;
use App\Enums\Order\ShippingMethod;
use App\Enums\Order\Status;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Repositories\OrderDetailRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductVariantRepository;
use App\Stores\CartStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class OrderService
{
    /**
     * 稅率
     *
     * @var float
     */
    private const float TAX_RATE = 0.05;

    /**
     * 免運門檻
     *
     * @var int
     */
    private const int FREE_SHIPPING_THRESHOLD = 1000;

    /**
     * 運費
     *
     * @var int
     */
    private const int SHIPPING_FEE = 80;

    /**
     * 結帳鎖定秒數
     *
     * @var int
     */
    private const int CHECKOUT_LOCK_SECONDS = 10;

    /**
     * 結帳鎖定等待秒數
     *
     * @var int
     */
    private const int CHECKOUT_LOCK_WAIT_SECONDS = 3;

    /**
     * 建構子
     *
     * @return void
     */
    public function __construct(
        private OrderRepository $orderRepository,
        private OrderDetailRepository $orderDetailRepository,
        private ProductVariantRepository $productVariantRepository,
        private CartStore $cartStore,
        private ConnectionInterface $db,
        private LoggerInterface $logger,
        private CacheManager $cache,
        private Dispatcher $events,
    ) {}

    /**
     * 從會員購物車建立訂單
     *
     * @return array<string, mixed>
     */
    public function storeOrder(
        int $memberId,
        string $idempotencyKey,
        int $paymentMethod,
        ?int $shippingMethod = null,
        ?string $storeCode = null,
        ?string $storeType = null,
        ?string $storeName = null,
        ?string $storeAddress = null,
    ): array {
        $shippingMethod ??= ShippingMethod::HOME_DELIVERY->value;

        try {
            return $this->cache->lock("checkout:member:{$memberId}", self::CHECKOUT_LOCK_SECONDS)
                ->block(self::CHECKOUT_LOCK_WAIT_SECONDS, fn () => $this->storeOrderWithLock($memberId, $idempotencyKey, $paymentMethod, $shippingMethod, $storeCode, $storeType, $storeName, $storeAddress));
        } catch (LockTimeoutException $e) {
            return [
                'status' => 409,
                'message' => '訂單建立中，請稍後再試。',
            ];
        }
    }

    /**
     * 產生訂單編號
     */
    private function generateOrderNumber(): string
    {
        $randomString = substr(str_shuffle('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 6);

        return sprintf('ORD%s%s', now()->format('Ymd'), $randomString);
    }

    /**
     * 計算稅額
     */
    private function calculateTaxAmount(int $itemsSubtotal): int
    {
        return $itemsSubtotal - (int) floor($itemsSubtotal / (1 + self::TAX_RATE));
    }

    /**
     * 計算運費
     */
    private function calculateShippingFee(int $itemsSubtotal): int
    {
        return $itemsSubtotal >= self::FREE_SHIPPING_THRESHOLD
            ? 0
            : self::SHIPPING_FEE;
    }

    /**
     * 解析訂單資料
     *
     * @return array<string, mixed>
     */
    private function parseOrderData(Order $order): array
    {
        $order->load('orderDetails');

        return [
            'id' => $order->id,
            'number' => $order->number,
            'total_amount' => $order->total_amount,
            'tax_amount' => $order->tax_amount,
            'shipping_fee' => $order->shipping_fee,
            'shipping_method' => $order->shipping_method,
            'store_type' => $order->store_type,
            'store_code' => $order->store_code,
            'store_name' => $order->store_name,
            'store_address' => $order->store_address,
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'items' => $order->orderDetails->map(fn ($orderDetail) => [
                'product_variant_id' => $orderDetail->product_variant_id,
                'product_name' => $orderDetail->product_name,
                'product_sku' => $orderDetail->product_sku,
                'product_color' => $orderDetail->product_color,
                'product_size' => $orderDetail->product_size,
                'product_price' => $orderDetail->product_price,
                'quantity' => $orderDetail->quantity,
                'subtotal' => $orderDetail->subtotal,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storeOrderWithLock(int $memberId, string $idempotencyKey, int $paymentMethod, int $shippingMethod, ?string $storeCode, ?string $storeType, ?string $storeName, ?string $storeAddress): array
    {
        try {
            // 取得既有訂單資料
            $existingOrder = $this->orderRepository->first([
                ['member_id', $memberId],
                ['idempotency_key', $idempotencyKey],
            ]);

            // 檢查訂單是否已存在
            if (! empty($existingOrder)) {
                return [
                    'status' => 200,
                    'message' => '訂單已存在。',
                    'data' => $this->parseOrderData($existingOrder),
                ];
            }

            // 取得購物車內容
            $cartItems = $this->cartStore->getItems($memberId);

            // 檢查購物車是否有可建立訂單的產品
            if (empty($cartItems)) {
                throw new RuntimeException('購物車沒有可建立訂單的產品。', 409);
            }

            $order = $existingOrder = null;
            $this->db->transaction(function () use ($memberId, $idempotencyKey, $paymentMethod, $shippingMethod, $storeCode, $storeType, $storeName, $storeAddress, &$cartItems, &$order, &$existingOrder) {
                // 取得既有訂單資料
                $existingOrder = $this->orderRepository->first([
                    ['member_id', $memberId],
                    ['idempotency_key', $idempotencyKey],
                ]);

                // 檢查訂單是否已存在
                if (! empty($existingOrder)) {
                    $order = $existingOrder;

                    return;
                }

                $totalAmount = 0;
                $cartItems = collect($cartItems)->sortBy('product_variant_id')->values()->all();
                $orderDetailsData = [];
                foreach ($cartItems as $item) {
                    $productVariant = $this->productVariantRepository->first(['id', $item['product_variant_id']]);

                    // 檢查購物車中的產品是否存在
                    if (empty($productVariant)) {
                        throw new RuntimeException('購物車包含不存在的產品。', 409);
                    }

                    // 檢查購物車中的產品庫存是否足夠
                    if ($productVariant->stock_quantity < $item['quantity']) {
                        throw new RuntimeException('產品庫存不足。', 409);
                    }

                    // 扣除產品庫存
                    $updatedRows = $this->productVariantRepository->updateStockQuantity($productVariant->id, $item['quantity']);

                    if ($updatedRows < 1) {
                        throw new RuntimeException('產品庫存不足。', 409);
                    }

                    $subtotal = $productVariant->price * $item['quantity'];
                    $totalAmount += $subtotal;

                    $productVariant->load([
                        'product',
                        'productSpec',
                    ]);
                    $orderDetailsData[] = [
                        'product_variant_id' => $productVariant->id,
                        'product_name' => $productVariant->product->name,
                        'product_sku' => $productVariant->sku,
                        'product_color' => $productVariant->productSpec->color,
                        'product_size' => $productVariant->productSpec->size,
                        'product_price' => $productVariant->price,
                        'quantity' => $item['quantity'],
                        'subtotal' => $subtotal,
                    ];
                }

                // 產生唯一訂單編號
                do {
                    $orderNumber = $this->generateOrderNumber();
                } while ($this->orderRepository->exists(['number', $orderNumber]));

                // 建立訂單
                $order = $this->orderRepository->create([
                    'member_id' => $memberId,
                    'number' => $orderNumber,
                    'idempotency_key' => $idempotencyKey,
                    'total_amount' => $totalAmount + $this->calculateShippingFee($totalAmount),
                    'tax_amount' => $this->calculateTaxAmount($totalAmount),
                    'shipping_fee' => $this->calculateShippingFee($totalAmount),
                    'shipping_method' => $shippingMethod,
                    'store_type' => $storeType,
                    'store_code' => $storeCode,
                    'store_name' => $storeName,
                    'store_address' => $storeAddress,
                    'status' => Status::STOCKING->value,
                    'payment_method' => $paymentMethod,
                    'payment_status' => PaymentStatus::UNPAID->value,
                ]);

                // 建立訂單明細
                $orderDetailsData = array_map(fn ($item) => array_merge(['order_id' => $order->id], $item), $orderDetailsData);
                if ($this->orderDetailRepository->createMany($orderDetailsData) === false) {
                    throw new RuntimeException('建立訂單明細失敗。', 503);
                }

                // 清空購物車
                $this->cartStore->clearCart($memberId);
            });

            if ($order !== null && $order === $existingOrder) {
                return [
                    'status' => 200,
                    'message' => '訂單已存在。',
                    'data' => $this->parseOrderData($order),
                ];
            }

            if ($order instanceof Order) {
                $this->events->dispatch(new OrderCreated($order->id));
            }

            return [
                'status' => 201,
                'message' => '訂單已建立。',
                'data' => $order instanceof Order ? $this->parseOrderData($order) : null,
            ];
        } catch (QueryException $e) {
            // 若新增訂單時發生唯一索引衝突，表示該 Idempotency-Key 已被使用，回傳既有訂單。
            if ($e->getCode() === '23000') {
                // 取得既有訂單資料
                $existingOrder = $this->orderRepository->first([
                    ['member_id', $memberId],
                    ['idempotency_key', $idempotencyKey],
                ]);

                if (empty($existingOrder)) {
                    $this->logger->error('建立訂單失敗', [
                        'member_id' => $memberId,
                        'idempotency_key' => $idempotencyKey,
                        'payment_method' => $paymentMethod,
                        'exception' => $e,
                    ]);

                    return [
                        'status' => 503,
                        'message' => '訂單建立失敗，請稍後再試。',
                    ];
                }

                return [
                    'status' => 200,
                    'message' => '訂單已存在。',
                    'data' => $this->parseOrderData($existingOrder),
                ];
            } else {
                $this->logger->error('建立訂單失敗', [
                    'member_id' => $memberId,
                    'idempotency_key' => $idempotencyKey,
                    'payment_method' => $paymentMethod,
                    'exception' => $e,
                ]);

                return [
                    'status' => 503,
                    'message' => '訂單建立失敗，請稍後再試。',
                ];
            }
        } catch (Throwable $e) {
            $code = (int) $e->getCode();

            if (in_array($code, [409], true)) {
                $this->logger->warning('建立訂單失敗', [
                    'member_id' => $memberId,
                    'idempotency_key' => $idempotencyKey,
                    'payment_method' => $paymentMethod,
                    'exception' => $e,
                ]);

                return [
                    'status' => $code,
                    'message' => $e->getMessage(),
                ];
            } else {
                $this->logger->error('建立訂單失敗', [
                    'member_id' => $memberId,
                    'idempotency_key' => $idempotencyKey,
                    'payment_method' => $paymentMethod,
                    'exception' => $e,
                ]);

                return [
                    'status' => 503,
                    'message' => '訂單建立失敗，請稍後再試。',
                ];
            }
        }
    }
}
