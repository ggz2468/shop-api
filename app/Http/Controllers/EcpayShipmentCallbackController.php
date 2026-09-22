<?php

namespace App\Http\Controllers;

use App\Services\EcpayShipmentCallbackService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EcpayShipmentCallbackController extends Controller
{
    /**
     * @return void
     */
    public function __construct(
        private EcpayShipmentCallbackService $ecpayShipmentCallbackService,
    ) {}

    public function __invoke(Request $request): Response
    {
        $result = $this->ecpayShipmentCallbackService->handle($request->all());

        return response($result['content'], $result['status'])
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
