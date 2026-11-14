# 訂單測試案例

## 取消訂單

**API Endpoint：** `PATCH /api/orders/{order}/cancel`

以下表格列出已實作的測試契約。取消 API 及相關跨流程防護已完成實作，Outside-in TDD 已進入 Green；執行紀錄見第 7 節。

- API 規格：[docs/api/orders.md](../api/orders.md)
- 業務規則：[docs/business-rules/order-rules.md](../business-rules/order-rules.md)
- 一般測試：[tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)
- 並行測試：[tests/Feature/OrderCancellationConcurrencyTest.php](../../tests/Feature/OrderCancellationConcurrencyTest.php)

### 1. 身份、路由與取消資格

下表方法皆位於 [tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)。狀態矩陣中的「允許」皆以其餘取消資格成立為前提；已取消訂單另走冪等回應。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 未登入，指定既有或不存在訂單 | `401`，資料不變 | `test_guest_cannot_cancel_an_existing_or_missing_order` |
| 指定其他會員訂單，包含偽造 member_id | `404`，資料不變 | `test_member_cannot_cancel_another_members_order_even_when_supplying_the_owner_id` |
| 指定不存在的數字 ID | `404` | `test_missing_order_returns_404` |
| 文字、負數、小數、數字前綴、科學記號 ID | 登入前後皆 `404`，有效訂單不受影響 | `test_invalid_identifiers_return_404_before_and_after_authentication` |
| 訂單操作路由的非法 ID | 取消等路由皆拒絕，資料不變 | `test_order_action_routes_reject_non_numeric_identifiers` |
| 非已取消訂單之訂單狀態 × 付款狀態組合 | 僅備貨中且未付款／付款失敗回傳 `200`；其餘為 `409` | `test_cancellation_requires_stocking_and_unpaid_or_failed_payment` |
| 未付款／付款失敗，但較舊或最新交易已授權 | `409`，所有交易及庫存不變 | `test_authorized_transaction_prevents_cancellation_even_when_order_payment_state_is_stale` |
| 未付款／付款失敗，但較舊或最新交易已付款 | `409`，所有交易及庫存不變 | `test_paid_transaction_prevents_cancellation_even_when_order_payment_state_is_stale` |
| 未付款／付款失敗，僅有已退款交易 | `200`，回補一次，退款歷史與原付款狀態不變 | `test_refunded_transaction_does_not_prevent_cancellation_or_change_refund_history` |
| 同會員或其他會員的別筆訂單有授權、付款或物流 | 不影響本訂單取消，不改寫別筆資料 | `test_other_orders_payment_and_shipment_records_do_not_prevent_cancellation` |
| 任一狀態的物流紀錄存在 | `409`，不改資料且不送外部請求 | `test_any_existing_shipment_prevents_cancellation` |

### 2. 成功結果、冪等與 rollback

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 成功取消 | 狀態已取消、待付款交易已取消、回補庫存，保留金額及明細 | `test_cancellation_requires_stocking_and_unpaid_or_failed_payment` |
| 重複取消相同訂單 | 皆 `200`，只回補一次，其他訂單不變 | `test_repeated_cancellation_returns_200_without_restoring_stock_again_or_touching_another_order` |
| 原本已取消 | `200`，不再次修改交易、訂單或庫存 | `test_already_canceled_order_does_not_restore_stock_or_rewrite_terminal_transactions` |
| 多筆待付款與終止交易混合 | 取消所有待付款；失敗、已取消、已過期、已退款歷史不變 | `test_cancellation_cancels_all_pending_transactions_but_preserves_terminal_history` |
| 沒有付款交易且取消後收到 OrderCreated | 可取消；延遲事件不建立新交易 | `test_late_order_created_event_cannot_create_a_payment_transaction_after_cancellation` |
| 寫入訂單、付款交易、第一筆庫存或第二筆庫存後注入真實 SQL 錯誤 | 有自己的 transaction；回傳 5xx，訂單、所有庫存、付款及明細完整還原，不洩漏 exception／trace／SQL | `test_sql_failure_rolls_back_order_payments_and_every_stock_change` |

### 3. 真實 Session Cookie 與 CSRF

這些測試實際呼叫 CSRF Cookie 與登入 endpoint，保存加密 Cookie，並清除記憶中的 guard 後再次驗證。不使用 `Sanctum::actingAs()` 或 PAT 代替登入。

測試只覆寫 CSRF middleware 的 PHPUnit 自動略過判斷，使真正的 Token 比對仍執行；不略過 Session、Cookie 或身份驗證 middleware。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 有效 Session 與 X-XSRF-TOKEN | 本人訂單可取消，不建立 PAT | `test_member_can_cancel_using_a_real_session_cookie_and_valid_csrf_token` |
| 有效 Session，但 Token 缺失或偽造 | `419`，資料不變 | `test_session_cancellation_rejects_missing_or_invalid_csrf_token` |
| 登出後重用舊 Cookie 查詢身份，再以匿名 Session 和有效 Token 取消 | 舊身份失效，取消 `401`，資料不變 | `test_logged_out_session_cannot_cancel_an_order` |

### 4. 真實資料庫並行測試

使用 MySQL、已提交的獨立 fixture connection、多個 PHP process 與明確的 SQL 同步閘門，不以 sleep 猜測請求先後。一般並行 HTTP 請求以測試專用 PAT 隔離 Session 鎖的影響；不代表 SPA 正式使用 PAT。

物流工作經容器解析真實 Listener／worker，使用正式事件鏈；對外 HTTP 以測試回應取代並記錄呼叫次數，不 mock 訂單、庫存、交易或物流更新邏輯。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 三個同時取消的請求 | 皆 `200`；兩個商品各回補一次，交易與訂單一致 | `test_three_concurrent_cancellations_restore_each_product_once` |
| 等鎖時訂單變已付款、交易變已付款／授權、出現物流、或訂單變配送中 | 鎖後重查，`409`，原資料及庫存不變 | `test_cancellation_rechecks_state_after_waiting_for_the_order_lock` |
| 付款成功／授權／失敗回呼，雙方各先取得 Order 鎖 | 成功／授權先則取消 `409`；失敗先仍可取消並保留失敗歷史；取消先則目前回呼契約為 `400` | `test_cancellation_and_signed_payment_callback_are_serialized_using_the_same_order_first_lock` |
| 付款重試，雙方各先取得鎖 | 取消先則重試 `409`；重試先的交易須被取消，不留下進行中交易 | `test_cancellation_and_payment_retry_do_not_leave_an_active_transaction_on_a_canceled_order` |
| 首次交易建立，雙方各先取得鎖 | 取消後不得新增交易，取消前已建立者須一併取消 | `test_initial_payment_creation_and_cancellation_share_the_order_lock_and_recheck_canceled_state` |
| 取消與不同會員 checkout 共用兩個商品，雙方各先完成第一筆庫存寫入 | 兩邊皆成功；初始 10／20、回補各 2、購買各 3，最終為 9／19，且新訂單及付款交易只建立一次 | `test_cancellation_and_another_members_checkout_preserve_shared_inventory` |
| 不同訂單同時取消、共用兩商品、明細儲存順序相反，雙方各先回補第一筆庫存 | 皆 `200`；各訂單先鎖自己再依商品 ID 升冪回補；初始 10／20、各訂單回補 2／3，最終 15／25；兩筆訂單及待付款交易取消，不新增物流 | `test_cancellations_of_different_orders_restore_shared_inventory_without_lost_updates` |
| 兩商品初始庫存各 1、取消各回補 2、checkout 各購買 3；回補提交前先暫停 checkout 的交易內查詢，提交後才放行 | 取消 `200`、checkout `201`；最終庫存各 0，僅一筆新訂單、兩筆明細及一筆待付款交易 | `test_insufficient_checkout_and_cancellation_use_only_committed_inventory` |
| 相同不足庫存 fixture；checkout 已讀到不足，取消回補第一筆但尚未提交時讓 checkout 繼續 | checkout `409`，不建立訂單、明細或付款交易且不寫庫存；取消 `200`，提交後最終庫存各 3 | `test_insufficient_checkout_and_cancellation_use_only_committed_inventory` |
| 延遲物流建立，雙方各先取得鎖 | 物流先則取消 `409` 並送單一次；取消先則不建立、不送單 | `test_cancellation_and_delayed_shipment_creation_share_the_order_lock` |
| 待送物流工作等鎖時訂單已取消，存在殘留物流 | 不送外部請求、不改物流、不再次回補 | `test_waiting_shipment_submission_rechecks_canceled_order_before_sending` |
| 已有待送物流，工作先取得鎖 | 送單一次，取消 `409`，不回補 | `test_shipment_submission_wins_before_cancellation_without_restoring_stock` |
| 配送／送達回呼，已取消訂單先取得鎖或回呼先取得鎖 | 已取消則 `200`／`1\|OK` 且所有資料不變；回呼先則轉配送／送達，取消 `409`，不回補 | `test_cancellation_and_signed_shipment_callback_preserve_order_and_inventory` |

已取消加殘留物流、延遲物流建立事件等 fixture 刻意模擬先前異常流程，不代表正常取消允許有物流。

鎖定觀察器要求相關既有訂單流程先鎖 Order，訂單／付款／庫存變更須位於 transaction，商品庫存依 ID 升冪更新。新訂單 checkout 不要求先鎖尚不存在的 Order，但共用商品的更新順序相同。

庫存不足的 checkout 可能在嘗試庫存寫入或取得商品鎖之前就回傳 `409`，因此新增案例以交易內查詢前／庫存讀取完成後的明確閘門安排先後，不強制它等待未提交的回補，也不以 HTTP 請求抵達順序推定結果。

#### 控制案例

用來驗證測試機制不是因無法登入、建立購物車、簽章錯誤或無法執行 worker 而產生 Red：

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 兩個付款重試請求 | `201`／`409`，僅一筆新待付款交易 | `test_payment_retry_control_serializes_two_real_requests_at_the_order_lock` |
| 真實 checkout process | `201`，扣兩筆庫存、建立訂單明細及付款交易 | `test_checkout_process_control_executes_the_real_order_creation_flow` |
| 真實 checkout 在交易內訂單查詢前／庫存讀取後暫停，沒有取消回補 | 兩個閘門皆可正常放行；庫存各 1、購買各 3 回傳 `409`，庫存及既有付款交易不變，不建立新訂單 | `test_insufficient_checkout_control_synchronizes_stock_reads_without_mutations` |
| 真實物流工作與簽章回呼 | 建立並送單一次，回呼成功轉為配送中，庫存不變 | `test_shipment_process_control_executes_real_workers_and_signed_callback` |

#### 物流 transaction 與 queue 時序

以下測試位於 [tests/Feature/ShipmentCreationFlowTest.php](../../tests/Feature/ShipmentCreationFlowTest.php)，實際執行事件鏈與同步 queue，以 HTTP fake 記錄外部送單：

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 在明確 transaction 內處理付款成功並建立物流，之後 commit | commit 前不得送單或通知；commit 後送單一次、物流改為已建立並通知會員 | `test_shipment_submission_and_notification_wait_for_transaction_commit` |
| 相同事件鏈，但來源 transaction rollback | 不留下物流，不送單、不通知 | `test_shipment_submission_and_notification_wait_for_transaction_commit` |

#### Listener 局部測試

沿用專案慣例放置於 `tests/Unit/`，直接呼叫 Listener 並使用真實資料庫；嚴格分類屬於 database integration test，不以 mock 證明鎖定或 rollback。跨流程交易與並行保證仍由上述 Feature Test 驗證。新增測試方法皆附功能註解。

| 測試檔案 | 情境 | 預期結果 | 測試方法 |
|---|---|---|---|
| [tests/Unit/RestoreProductVariantStockTest.php](../../tests/Unit/RestoreProductVariantStockTest.php) | 同商品出現在兩筆明細，數量為 2／3，初始庫存 10 | 庫存變 15，該商品僅更新一次，訂單已取消，明細保持不變 | `test_handle_aggregates_duplicate_variant_details_into_one_stock_update` |
| [tests/Unit/RestoreProductVariantStockTest.php](../../tests/Unit/RestoreProductVariantStockTest.php) | 回補事件指定不存在訂單 | 拋出 ModelNotFoundException，不改其他商品庫存 | `test_handle_throws_model_not_found_exception_when_order_is_missing` |
| [tests/Unit/CreatePaymentTransactionTest.php](../../tests/Unit/CreatePaymentTransactionTest.php) | 已取消訂單收到延遲 OrderCreated，尚無付款交易 | 不建立付款交易、不派送 PaymentInitiated，訂單不變 | `test_handle_does_not_create_payment_or_dispatch_payment_initiated_for_canceled_order` |
| [tests/Unit/CreateShipmentTest.php](../../tests/Unit/CreateShipmentTest.php) | 已取消訂單收到延遲 PaymentSucceeded | 不建立物流、不派送 ShipmentRequested／ShipmentCreated，訂單及交易不變 | `test_handle_does_not_create_shipment_or_dispatch_events_for_canceled_order` |
| [tests/Unit/SubmitShipmentRequestTest.php](../../tests/Unit/SubmitShipmentRequestTest.php) | 已取消訂單保留待建立物流，且 payload 完整 | 不送 HTTP、不派送 ShipmentCreated／ShipmentFailed，訂單及物流不變 | `test_handle_does_not_submit_or_dispatch_events_for_ineligible_shipment` |
| [tests/Unit/SubmitShipmentRequestTest.php](../../tests/Unit/SubmitShipmentRequestTest.php) | 備貨中訂單的物流為所有非 PENDING 狀態，且 payload 完整 | 各狀態皆不送 HTTP、不派送結果事件，訂單及物流不變 | `test_handle_does_not_submit_or_dispatch_events_for_ineligible_shipment` |

### 5. RateLimiter

下表位於 [tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)；目前門檻對應 [app/Providers/AppServiceProvider.php](../../app/Providers/AppServiceProvider.php) 的 `orders` 寫入限流設定。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 同會員每分鐘第 11 次寫入 | `429`、Retry-After、不改資料；61 秒後可取消 | `test_eleventh_write_in_one_minute_is_rate_limited_without_canceling_the_target_order` |
| 與付款交易寫入共用分鐘額度 | 跨訂單寫入共用額度，不影響讀取及其他會員 | `test_member_minute_bucket_is_shared_across_orders_and_payment_writes_but_not_reads_or_other_members` |
| 同 IP 跨會員每小時第 51 次寫入 | `429`；其他 IP 可操作，3601 秒後原 IP 可操作 | `test_fifty_first_write_from_the_same_ip_in_one_hour_is_rate_limited_across_members` |

### 6. 尚未定義及保證範圍

- 延遲付款成功的對帳、退款、人工處理及付款事實保存：待定，不因既有回呼 `400` 測試而視為已解決。
- 物流取消後回呼的 `200` 僅是確認通知且不改本機資料，不表示已撤銷供應商的物流單。
- 鎖競爭案例驗證指定先後順序；不宣稱已窮舉所有故障與死結組合。
- 自動化測試涵蓋本文件列出的情境，不代表外部供應商的對帳／退款或所有故障情境皆已驗證。

### 7. 驗證紀錄

2026-11-14 於 Laradock `workspace` container 執行：

- 實作前：取消相關兩個測試類別為 169 通過、87 失敗，確認原先 Red 基準。
- 實作後：完整 `php artisan test --compact` 為 **648 通過、0 失敗、5,289 assertions**。
- 本次異動的 PHP 檔案通過 Laravel Pint 格式檢查。

Green 驗證同時修正測試機制：快照先重新讀取 MySQL 已儲存的資料，避免 JSON 正規化及預設欄位產生假差異；process 同步檢查包含已緩存的 marker，避免漏讀後誤判逾時。未放寬資格、庫存、rollback 或並行業務斷言。

2026-11-15 補上上述 Listener 局部測試，共新增 11 個案例（含 data provider 展開）。於 Laradock `workspace` 執行四個對應測試類別，結果為 **29 通過、0 失敗、129 assertions**；四個異動測試檔案通過 Pint。此次僅新增測試與更新文件，未修改正式程式，亦未重新執行完整套件。
