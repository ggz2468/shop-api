<?php

namespace App\Http\Controllers;

use App\Services\EcpayShipmentStoreMapCallbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EcpayShipmentStoreMapCallbackController extends Controller
{
    /**
     * 建構子
     *
     * @return void
     */
    public function __construct(
        private EcpayShipmentStoreMapCallbackService $ecpayShipmentStoreMapCallbackService,
    ) {}

    /**
     * 處理綠界電子地圖回應資訊
     */
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $result = $this->ecpayShipmentStoreMapCallbackService->handle($request->all());

        if (array_key_exists('redirect_url', $result)) {
            return redirect()->away($result['redirect_url'], $result['status']);
        }

        return response($result['content'], $result['status'])
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
