<?php

namespace App\Repositories;

use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderRepository extends Repository
{
    /**
     * 預設每頁資料筆數
     */
    public const int DEFAULT_ROW_COUNTS_PER_PAGE = 10;

    /**
     * 預設頁碼
     */
    public const int DEFAULT_PAGE = 1;

    /**
     * 取得會員的訂單列表
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, Order>
     */
    public function getOrders(int $memberId, int $rowCountsPerPage, int $page): LengthAwarePaginator
    {
        return Order::query()
            ->where('member_id', $memberId)
            ->withSum('orderDetails as item_quantity', 'quantity')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $rowCountsPerPage, page: $page);
    }
}
