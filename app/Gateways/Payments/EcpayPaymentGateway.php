<?php

namespace App\Gateways\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentTransaction\PaymentMethod;
use App\Models\PaymentTransaction;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

class EcpayPaymentGateway implements PaymentGateway
{
    /**
     * 建構子
     *
     * @return void
     */
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * 建立呼叫綠界金流 API 時所需的請求資訊
     *
     * @return array{
     *     action: string,
     *     method: string,
     *     params: array{
     *         MerchantID: string,
     *         MerchantTradeNo: string,
     *         MerchantTradeDate: string,
     *         PaymentType: string,
     *         TotalAmount: int,
     *         TradeDesc: string,
     *         ItemName: string,
     *         ReturnURL: string,
     *         PaymentInfoURL?: string,
     *         ClientRedirectURL?: string,
     *         ClientBackURL: string,
     *         ChoosePayment: string,
     *         EncryptType: int,
     *         CheckMacValue: string,
     *     }
     * }
     */
    public function buildPaymentRequest(PaymentTransaction $paymentTransaction): array
    {
        $paymentTransaction->loadMissing('order.orderDetails');
        $orderDetails = $paymentTransaction->order->orderDetails;
        $itemName = $orderDetails->isEmpty() ? 'Order items' : $orderDetails
            ->map(fn ($item) => "{$item->product_name} x {$item->quantity}")
            ->implode('#');
        $params = [
            'MerchantID' => $this->config->get('services.ecpay.merchant_id'),
            'MerchantTradeNo' => $paymentTransaction->merchant_trade_no,
            'MerchantTradeDate' => $paymentTransaction->created_at?->format('Y/m/d H:i:s') ?? now()->format('Y/m/d H:i:s'),
            'PaymentType' => 'aio',
            'TotalAmount' => $paymentTransaction->amount,
            'TradeDesc' => 'Order payment',
            'ItemName' => $itemName,
            'ReturnURL' => $this->config->get('services.ecpay.return_url'),
            'ClientBackURL' => $this->config->get('services.ecpay.client_back_url'),
            'ChoosePayment' => $this->resolveChoosePayment($paymentTransaction->payment_method),
            'EncryptType' => 1,
        ];

        if ($this->requiresPaymentInfoUrl($paymentTransaction->payment_method)) {
            $params['PaymentInfoURL'] = $this->config->get('services.ecpay.payment_info_url')
                ?? $params['ReturnURL'];
            $params['ClientRedirectURL'] = $this->config->get('services.ecpay.client_redirect_url')
                ?? $params['ClientBackURL'];
        }

        $checkMacValue = $this->makeCheckMacValue($params);

        return [
            'action' => $this->config->get('services.ecpay.payment_action_url'),
            'method' => 'POST',
            'params' => array_merge($params, ['CheckMacValue' => $checkMacValue]),
        ];
    }

    private function resolveChoosePayment(int $paymentMethod): string
    {
        return match ($paymentMethod) {
            PaymentMethod::CREDIT_CARD->value => 'Credit',
            PaymentMethod::ATM->value => 'ATM',
            PaymentMethod::CVS->value => 'CVS',
            PaymentMethod::BARCODE->value => 'BARCODE',
        };
    }

    private function requiresPaymentInfoUrl(int $paymentMethod): bool
    {
        return in_array($paymentMethod, [
            PaymentMethod::ATM->value,
            PaymentMethod::CVS->value,
            PaymentMethod::BARCODE->value,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function makeCheckMacValue(array $payload): string
    {
        unset($payload['CheckMacValue']);

        uksort($payload, 'strcasecmp');

        $encoded = 'HashKey='.$this->config->get('services.ecpay.hash_key')
            .'&'.urldecode(http_build_query($payload))
            .'&HashIV='.$this->config->get('services.ecpay.hash_iv');

        $encoded = strtolower(urlencode($encoded));
        $encoded = str_replace(
            ['%2d', '%5f', '%2e', '%21', '%2a', '%28', '%29'],
            ['-', '_', '.', '!', '*', '(', ')'],
            $encoded,
        );

        return strtoupper(hash('sha256', $encoded));
    }
}
