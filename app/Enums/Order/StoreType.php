<?php

namespace App\Enums\Order;

enum StoreType: string
{
    /**
     * 7-ELEVEN
     */
    case UNIMART = 'UNIMART';

    /**
     * 全家便利商店
     */
    case FAMI = 'FAMI';

    /**
     * 萊爾富
     */
    case HILIFE = 'HILIFE';

    public function label(): string
    {
        return match ($this) {
            self::UNIMART => '7-ELEVEN',
            self::FAMI => '全家便利商店',
            self::HILIFE => '萊爾富',
        };
    }
}
