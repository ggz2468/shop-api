<?php

namespace App\Enums\Order;

enum Status: int
{
    /**
     * 已取消
     */
    case CANCELED = 1;

    /**
     * 已完成
     */
    case COMPLETED = 2;

    /**
     * 產品備貨中
     */
    case STOCKING = 3;

    /**
     * 產品配送中
     */
    case DELIVERING = 4;

    /**
     * 產品已送達
     */
    case DELIVERED = 5;

    public function label(): string
    {
        return match ($this) {
            self::CANCELED => '已取消',
            self::COMPLETED => '已完成',
            self::STOCKING => '產品備貨中',
            self::DELIVERING => '產品配送中',
            self::DELIVERED => '產品已送達',
        };
    }
}
