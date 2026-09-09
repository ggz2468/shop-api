<?php

namespace App\Enums\PaymentTransaction;

enum PaymentMethod: int
{
    /**
     * 信用卡
     */
    case CREDIT_CARD = 1;

    /**
     * ATM 轉帳
     */
    case ATM = 2;

    /**
     * 超商代碼
     */
    case CVS = 3;

    /**
     * 超商條碼
     */
    case BARCODE = 4;
}
