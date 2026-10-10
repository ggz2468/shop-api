<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderPaymentTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderPaymentTransactionController extends Controller
{
    public function __construct(
        private OrderPaymentTransactionService $orderPaymentTransactionService,
    ) {}

    public function store(Request $request, Order $order): JsonResponse
    {
        $result = $this->orderPaymentTransactionService->createForOrder($request->user()->id, $order);
        $response = [
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ];

        return response()->json($response, $result['status']);
    }
}
