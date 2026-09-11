<?php

namespace App\Http\Controllers;

use App\Enums\ShipmentStoreMapRequest\StoreType;
use App\Services\ShipmentStoreMapRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShipmentStoreMapRequestController extends Controller
{
    /**
     * 建構子
     *
     * @return void
     */
    public function __construct(
        private ShipmentStoreMapRequestService $shipmentStoreMapRequestService,
    ) {}

    /**
     * 建立超商電子地圖選擇請求。
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_type' => [
                'required',
                'string',
                Rule::in(array_map(fn (StoreType $storeType): string => $storeType->value, StoreType::cases())),
            ],
            'device' => [
                'nullable',
                'integer',
                Rule::in([0, 1]),
            ],
        ]);

        $result = $this->shipmentStoreMapRequestService->create(
            $request->user()->id,
            $validated['store_type'],
            $validated['device'] ?? null,
        );
        $response = [];

        if (array_key_exists('message', $result)) {
            $response['message'] = $result['message'];
        }

        if (array_key_exists('data', $result)) {
            $response['data'] = $result['data'];
        }

        return response()->json($response, $result['status']);
    }

    /**
     * 查詢超商電子地圖選擇結果。
     */
    public function show(Request $request, string $selectionToken): JsonResponse
    {
        $result = $this->shipmentStoreMapRequestService->getSelectionResult(
            $request->user()->id,
            $selectionToken,
        );
        $response = [];

        if (array_key_exists('message', $result)) {
            $response['message'] = $result['message'];
        }

        if (array_key_exists('data', $result)) {
            $response['data'] = $result['data'];
        }

        return response()->json($response, $result['status']);
    }
}
