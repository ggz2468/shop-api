<?php

namespace App\Enums\Order;

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

    public function label(): string
    {
        return match ($this) {
            self::CREDIT_CARD => '信用卡',
            self::ATM => 'ATM 轉帳',
            self::CVS => '超商代碼',
            self::BARCODE => '超商條碼',
        };
    }
}
