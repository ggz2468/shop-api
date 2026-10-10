<?php

namespace App\Contracts;

use App\Models\PaymentTransaction;

interface PaymentGateway
{
    /**
     * 建立呼叫第三方金流 API 時所需的請求資訊
     *
     * @return array{action: string, method: string, params: array<string, mixed>}
     */
    public function buildPaymentRequest(PaymentTransaction $paymentTransaction): array;
}
