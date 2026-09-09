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
}
