<?php

namespace App\Http\Controllers;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\ShippingMethod;
use App\Enums\Order\StoreType;
use App\Http\Resources\OrderDetailResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService,
    ) {}

    /**
     * 訂單列表
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        // 資料格式驗證
        $validated = $request->validate([
            'row_counts_per_page' => 'integer|min:1|max:30|nullable',
            'page' => 'integer|min:1|nullable',
        ]);

        $orders = $this->orderService->getOrders(
            (int) $request->user()->id,
            (int) ($validated['row_counts_per_page'] ?? OrderRepository::DEFAULT_ROW_COUNTS_PER_PAGE),
            (int) ($validated['page'] ?? OrderRepository::DEFAULT_PAGE),
        );

        return OrderResource::collection($orders->withQueryString());
    }

    /**
     * 訂單詳情
     */
    public function show(Request $request, Order $order): OrderDetailResource
    {
        $order = $this->orderService->getOrder((int) $request->user()->id, (string) $order->getKey());

        return new OrderDetailResource($order);
    }

    /**
     * 建立訂單
     */
    public function store(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        Validator::make([
            'idempotency_key' => $idempotencyKey,
        ], [
            'idempotency_key' => ['required', 'string', 'max:64'],
        ])->validate();

        $validated = $request->validate([
            'payment_method' => [
                'required',
                'integer',
                Rule::in(array_map(fn (PaymentMethod $paymentMethod): int => $paymentMethod->value, PaymentMethod::cases())),
            ],
            'shipping_method' => [
                'required',
                'integer',
                Rule::in(array_map(fn (ShippingMethod $shippingMethod): int => $shippingMethod->value, ShippingMethod::cases())),
            ],
            'recipient_name' => [
                'required',
                'string',
                'max:50',
            ],
            'recipient_phone' => [
                'required',
                'string',
                'regex:/^09\d{8}$/',
            ],
            'recipient_zip_code' => [
                Rule::requiredIf(fn (): bool => (int) $request->input('shipping_method') === ShippingMethod::HOME_DELIVERY->value),
                'nullable',
                'string',
                'regex:/^\d{3,6}$/',
            ],
            'recipient_address' => [
                Rule::requiredIf(fn (): bool => (int) $request->input('shipping_method') === ShippingMethod::HOME_DELIVERY->value),
                'nullable',
                'string',
                'max:500',
            ],
            'store_type' => [
                Rule::requiredIf(fn (): bool => (int) $request->input('shipping_method') === ShippingMethod::CONVENIENCE_STORE->value),
                'nullable',
                'string',
                Rule::in(array_map(fn (StoreType $storeType): string => $storeType->value, StoreType::cases())),
            ],
            'store_code' => [
                Rule::requiredIf(fn (): bool => (int) $request->input('shipping_method') === ShippingMethod::CONVENIENCE_STORE->value),
                'nullable',
                'string',
                'max:32',
            ],
            'store_name' => [
                Rule::requiredIf(fn (): bool => (int) $request->input('shipping_method') === ShippingMethod::CONVENIENCE_STORE->value),
                'nullable',
                'string',
                'max:50',
            ],
            'store_address' => [
                Rule::requiredIf(fn (): bool => (int) $request->input('shipping_method') === ShippingMethod::CONVENIENCE_STORE->value),
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $result = $this->orderService->storeOrder(
            memberId: $request->user()->id,
            idempotencyKey: $idempotencyKey,
            paymentMethod: $validated['payment_method'],
            shippingMethod: $validated['shipping_method'],
            recipientData: [
                'name' => $validated['recipient_name'] ?? null,
                'phone' => $validated['recipient_phone'] ?? null,
                'zip_code' => $validated['recipient_zip_code'] ?? null,
                'address' => $validated['recipient_address'] ?? null,
            ],
            storeData: [
                'code' => $validated['store_code'] ?? null,
                'type' => $validated['store_type'] ?? null,
                'name' => $validated['store_name'] ?? null,
                'address' => $validated['store_address'] ?? null,
            ],
        );

        $response = [
            'message' => $result['message'],
        ];

        if (array_key_exists('data', $result)) {
            $response['data'] = $result['data'];
        }

        return response()->json($response, $result['status']);
    }
}
