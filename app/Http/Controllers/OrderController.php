<?php

namespace App\Http\Controllers;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\ShippingMethod;
use App\Enums\Order\StoreType;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * 建構子
     *
     * @return void
     */
    public function __construct(
        private OrderService $orderService,
    ) {}

    /**
     * 建立訂單
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
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
                'max:20',
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
