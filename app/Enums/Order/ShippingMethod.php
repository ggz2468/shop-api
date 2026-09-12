<?php

namespace App\Enums\Order;

enum ShippingMethod: int
{
    /**
     * 宅配
     */
    case HOME_DELIVERY = 1;

    /**
     * 超商取貨
     */
    case CONVENIENCE_STORE = 2;

    public function label(): string
    {
        return match ($this) {
            self::HOME_DELIVERY => '宅配',
            self::CONVENIENCE_STORE => '超商取貨',
        };
    }
}
