<?php

namespace App\Enums\PaymentTransaction;

enum Status: int
{
    /**
     * 待建立付款
     */
    case PENDING = 1;

    /**
     * 已授權
     */
    case AUTHORIZED = 2;

    /**
     * 已付款
     */
    case PAID = 3;

    /**
     * 付款失敗
     */
    case FAILED = 4;

    /**
     * 已取消
     */
    case CANCELED = 5;

    /**
     * 已退款
     */
    case REFUNDED = 6;

    /**
     * 已過期
     */
    case EXPIRED = 7;
}
