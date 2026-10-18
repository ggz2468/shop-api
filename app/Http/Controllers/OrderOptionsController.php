<?php

namespace App\Http\Controllers;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\ShippingMethod;
use App\Enums\Order\Status;
use App\Enums\Order\StoreType;
use Illuminate\Http\JsonResponse;

class OrderOptionsController extends Controller
{
    /**
     * 提供訂單相關選項與狀態字典。
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'order_statuses' => array_map(fn (Status $status): array => [
                    'value' => $status->value,
                    'code' => $status->name,
                    'label' => $status->label(),
                ], Status::cases()),
                'payment_statuses' => array_map(fn (PaymentStatus $status): array => [
                    'value' => $status->value,
                    'code' => $status->name,
                    'label' => $status->label(),
                ], PaymentStatus::cases()),
                'payment_methods' => array_map(fn (PaymentMethod $paymentMethod): array => [
                    'value' => $paymentMethod->value,
                    'code' => $paymentMethod->name,
                    'label' => $paymentMethod->label(),
                ], PaymentMethod::cases()),
                'shipping_methods' => array_map(fn (ShippingMethod $shippingMethod): array => [
                    'value' => $shippingMethod->value,
                    'code' => $shippingMethod->name,
                    'label' => $shippingMethod->label(),
                ], ShippingMethod::cases()),
                'store_types' => array_map(fn (StoreType $storeType): array => [
                    'value' => $storeType->value,
                    'code' => $storeType->name,
                    'label' => $storeType->label(),
                ], StoreType::cases()),
                'defaults' => [
                    'payment_method' => PaymentMethod::CREDIT_CARD->value,
                    'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                ],
            ],
        ]);
    }
}
