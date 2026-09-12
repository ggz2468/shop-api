<?php

namespace Tests\Feature;

use App\Enums\Order\PaymentMethod;
use App\Enums\Order\ShippingMethod;
use App\Enums\Order\StoreType;
use Tests\TestCase;

class OrderOptionsControllerTest extends TestCase
{
    /**
     * 訂單選項: 訪客可以取得建立訂單所需選項。
     */
    public function test_guest_can_get_order_options(): void
    {
        $response = $this->getJson('/api/orders/options');

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'payment_methods' => [
                        [
                            'value' => PaymentMethod::CREDIT_CARD->value,
                            'code' => PaymentMethod::CREDIT_CARD->name,
                            'label' => '信用卡',
                        ],
                        [
                            'value' => PaymentMethod::ATM->value,
                            'code' => PaymentMethod::ATM->name,
                            'label' => 'ATM 轉帳',
                        ],
                        [
                            'value' => PaymentMethod::CVS->value,
                            'code' => PaymentMethod::CVS->name,
                            'label' => '超商代碼',
                        ],
                        [
                            'value' => PaymentMethod::BARCODE->value,
                            'code' => PaymentMethod::BARCODE->name,
                            'label' => '超商條碼',
                        ],
                    ],
                    'shipping_methods' => [
                        [
                            'value' => ShippingMethod::HOME_DELIVERY->value,
                            'code' => ShippingMethod::HOME_DELIVERY->name,
                            'label' => '宅配',
                        ],
                        [
                            'value' => ShippingMethod::CONVENIENCE_STORE->value,
                            'code' => ShippingMethod::CONVENIENCE_STORE->name,
                            'label' => '超商取貨',
                        ],
                    ],
                    'store_types' => [
                        [
                            'value' => StoreType::UNIMART->value,
                            'code' => StoreType::UNIMART->name,
                            'label' => '7-ELEVEN',
                        ],
                        [
                            'value' => StoreType::FAMI->value,
                            'code' => StoreType::FAMI->name,
                            'label' => '全家便利商店',
                        ],
                        [
                            'value' => StoreType::HILIFE->value,
                            'code' => StoreType::HILIFE->name,
                            'label' => '萊爾富',
                        ],
                    ],
                    'defaults' => [
                        'payment_method' => PaymentMethod::CREDIT_CARD->value,
                        'shipping_method' => ShippingMethod::HOME_DELIVERY->value,
                    ],
                ],
            ]);
    }

    /**
     * 訂單選項: API 會套用 RateLimiter。
     */
    public function test_order_options_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 120; $attempt++) {
            $this->getJson('/api/orders/options')->assertOk();
        }

        $this->getJson('/api/orders/options')->assertStatus(429);
    }
}
