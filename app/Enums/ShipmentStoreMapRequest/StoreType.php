<?php

namespace App\Enums\ShipmentStoreMapRequest;

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

    /**
     * OK 超商
     */
    case OKMART = 'OKMART';
}
