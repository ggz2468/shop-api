# 訂單測試案例

## 建立訂單

**API Endpoint：** `POST /api/orders`

以下表格列出已實作的測試契約，涵蓋身份驗證、參數、建單結果、付款事件、冪等、rollback、並行及限流。建立 API 與所列測試已實作；執行紀錄見本節第 7 節。

- API 規格：[docs/api/orders.md](../api/orders.md)
- 業務規則：[docs/business-rules/order-rules.md](../business-rules/order-rules.md)
- 一般測試：[tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)
- 並行測試：[tests/Feature/OrderCreationConcurrencyTest.php](../../tests/Feature/OrderCreationConcurrencyTest.php)
- 跨取消流程並行測試：[tests/Feature/OrderCancellationConcurrencyTest.php](../../tests/Feature/OrderCancellationConcurrencyTest.php)
- Service 局部測試：[tests/Unit/OrderServiceTest.php](../../tests/Unit/OrderServiceTest.php)

### 1. 身份、Header 與建立參數

下表方法皆位於 [tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)。參數驗證案例以 mock 確認不呼叫 `storeOrder()`；不以此宣稱已驗證真實資料庫 rollback。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 未登入建立訂單 | `401` | `test_guest_cannot_create_order` |
| 缺少 Idempotency-Key header | `422`，`errors.idempotency_key` | `test_store_returns_422_when_idempotency_key_header_is_missing` |
| Idempotency-Key 為 65 個字元 | `422`，`errors.idempotency_key` | `test_store_returns_422_when_idempotency_key_header_is_too_long` |
| 缺少付款方式 | `422`，`errors.payment_method` | `test_store_returns_422_when_payment_method_is_missing` |
| 付款方式為 `0`、`99` 或 `abc` | 各輸入皆為 `422`，`errors.payment_method` | `test_store_returns_422_when_payment_method_is_invalid` |
| 缺少配送方式 | `422`，`errors.shipping_method` | `test_store_returns_422_when_shipping_method_is_missing` |
| 配送方式為 `0`、`99` 或 `abc` | 各輸入皆為 `422`，`errors.shipping_method` | `test_store_returns_422_when_shipping_method_is_invalid` |
| 宅配未提供收件人姓名、電話、郵遞區號及地址 | `422`，四個收件欄位皆有 validation error | `test_store_returns_422_when_required_recipient_information_is_missing` |
| 收件電話為 `091234567`、`0212345678`、`09123456789` 或 `09abcdefgh` | 各輸入皆為 `422`，`errors.recipient_phone` | `test_store_returns_422_when_recipient_phone_format_is_invalid` |
| 宅配郵遞區號為 `10`、`1000000`、`ABC` 或 `100-01` | 各輸入皆為 `422`，`errors.recipient_zip_code` | `test_store_returns_422_when_home_delivery_recipient_zip_code_format_is_invalid` |
| 宅配地址只有空白 | `422`，`errors.recipient_address` | `test_store_returns_422_when_home_delivery_recipient_address_is_blank` |
| 收件姓名 51 字、宅配地址 501 字 | `422`，姓名及地址皆有 validation error | `test_store_returns_422_when_recipient_information_exceeds_max_length` |
| 超商取貨缺少門市類型、代碼、名稱及地址 | `422`，四個門市欄位皆有 validation error | `test_store_returns_422_when_store_information_is_missing_for_convenience_store_shipping` |
| 門市類型為 `UNKNOWN` | `422`，`errors.store_type` | `test_store_returns_422_when_store_type_is_invalid` |
| 門市代碼 33 字、名稱 51 字、地址 501 字 | `422`，三個門市欄位皆有 validation error | `test_store_returns_422_when_store_information_exceeds_max_length` |

### 2. 成功結果、冪等與 rollback

#### HTTP 回應映射

下表位於 [tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)，以 mock Service 驗證 Controller 傳入登入會員、Header、配送／收件資料及 HTTP 回應，不驗證實際建單或金額計算。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 已登入會員使用宅配建立訂單 | 傳入登入會員 ID 與已驗證資料；Service 回傳 `201` 時保留 message、訂單編號、付款方式及明細 | `test_store_creates_order_for_authenticated_member` |
| 超商取貨未提供收件郵遞區號與地址 | 可呼叫 Service，兩個收件欄位為 null，門市資料完整；回傳 `201` | `test_store_allows_convenience_store_shipping_without_recipient_address` |
| Service 回傳同 key 的既有訂單 | `200`、「訂單已存在。」及原訂單編號 | `test_store_returns_existing_order_when_idempotency_key_was_already_used` |
| Service 回傳空購物車 | `409`、「購物車沒有可建立訂單的產品。」 | `test_store_returns_409_when_cart_is_empty` |
| Service 回傳庫存不足 | `409`、「產品庫存不足。」 | `test_store_returns_409_when_product_stock_is_insufficient` |
| Service 回傳暫時性建單失敗 | `503`、「訂單建立失敗，請稍後再試。」 | `test_store_returns_503_when_order_service_cannot_create_order` |

#### 真實建單、快照與付款事件

下表位於 [tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)，通過 HTTP 入口呼叫實際 Service／Repository。只驗證建單時派送事件的案例會 fake `OrderCreated`；付款事件鏈案例則保留真實事件並 fake 通知。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 超商取貨，商品單價 800、購買 2、初始庫存 5 | `201`；訂單備貨中／未付款，商品與門市快照正確；總額 1,600、稅額 77、運費 0；庫存 3、購物車清空、派送對應 OrderCreated | `test_store_persists_order_details_decrements_stock_clears_cart_and_dispatches_order_created` |
| 信用卡宅配，透過真實 OrderCreated／PaymentInitiated 事件鏈 | `201`；建立 ECPay 待付款交易，金額等於訂單總額，付款方式為信用卡；request 含商店交易編號、ChoosePayment 與 CheckMacValue；checkout 使用 POST 及設定的付款網址，response payload 為 null；通知會員 | `test_store_builds_payment_checkout_payload_through_events` |
| ATM 付款 | `201`；建立 ECPay、ATM、PENDING 交易，回應付款方式正確 | `test_store_creates_payment_transaction_with_matching_provider_for_non_credit_card_payment_methods`，ATM |
| 超商代碼付款 | `201`；建立 ECPay、CVS、PENDING 交易，回應付款方式正確 | `test_store_creates_payment_transaction_with_matching_provider_for_non_credit_card_payment_methods`，CVS |
| 超商條碼付款 | `201`；建立 ECPay、BARCODE、PENDING 交易，回應付款方式正確 | `test_store_creates_payment_transaction_with_matching_provider_for_non_credit_card_payment_methods`，BARCODE |
| 同會員、相同 key 先後建立兩次；初始庫存 5、購買 2 | `201`／`200` 且為同一訂單，資料庫僅一筆該會員／key 訂單，庫存 3，OrderCreated 僅派送一次 | `test_store_real_checkout_is_idempotent_for_same_member_and_key` |
| 購物車包含不存在的商品規格 | `409`、「購物車包含不存在的產品。」；不建立訂單，不派送 OrderCreated | `test_store_real_checkout_returns_409_when_cart_contains_missing_product_variant` |
| 單項商品庫存 1、購買 2 | `409`、「產品庫存不足。」；庫存仍為 1，不建立訂單，不派送 OrderCreated | `test_store_real_checkout_returns_409_when_stock_is_insufficient` |

#### 部分寫入後完整 rollback

下表皆對應 [tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php) 的 `test_store_rolls_back_partial_checkout_without_leaving_orders_details_or_payments`。購物車以反序加入兩項商品、各購買 2，第一項庫存為 5，第二項除不足案例外為 7。每個案例皆驗證資料庫原始欄位快照、購物車及 transaction depth，且不洩漏 exception、trace 或注入的內部欄位名稱。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 第一項已扣庫存，第二項僅有 1 件 | `409`；確認曾執行一筆庫存 UPDATE；兩項庫存還原，無訂單、明細、付款及事件，購物車不變 | `test_store_rolls_back_partial_checkout_without_leaving_orders_details_or_payments`，`second product insufficient` |
| 第二項加入購物車後刪除，第一項已扣庫存 | `409`；確認曾執行一筆庫存 UPDATE；第一項庫存還原、第二項維持刪除狀態，無訂單、明細、付款及事件，購物車不變 | `test_store_rolls_back_partial_checkout_without_leaving_orders_details_or_payments`，`second product missing` |
| 第一項庫存 UPDATE 成功後注入真實 SQL 錯誤 | `503`；確認曾執行一筆庫存 UPDATE；庫存完整還原，無訂單、明細、付款及事件，購物車不變 | `test_store_rolls_back_partial_checkout_without_leaving_orders_details_or_payments`，`SQL failure after first stock write` |
| 兩項庫存已扣、訂單 INSERT 成功後注入 SQL 錯誤 | `503`；確認曾執行兩筆庫存 UPDATE；庫存及訂單 rollback，無明細、付款及事件，購物車不變 | `test_store_rolls_back_partial_checkout_without_leaving_orders_details_or_payments`，`SQL failure after order insert` |
| 兩項庫存、訂單及明細已寫入，清空購物車前注入 SQL 錯誤 | `503`；確認曾執行兩筆庫存 UPDATE；庫存、訂單與明細 rollback，無付款及事件，購物車不變 | `test_store_rolls_back_partial_checkout_without_leaving_orders_details_or_payments`，`SQL failure after detail insert` |

SQL 故障是在成功寫入後由 query listener 注入的真實 MySQL 例外，不 mock Repository 回傳值；庫存寫入必須位於 Service 自己的 transaction 內，不能只依賴 RefreshDatabase 的外層交易。

#### Service 局部測試

以下方法皆位於 [tests/Unit/OrderServiceTest.php](../../tests/Unit/OrderServiceTest.php)。沿用專案 Unit 目錄慣例，但部分使用真實資料庫，部分以 mock 驗證內部分支；不將 mock 例外或回傳值視為真實並行證明。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 真實建立超商取貨訂單，單價 800、數量 2、庫存 5 | `201`；編號符合固定日期及 6 碼大寫英數字格式；總額 1,600、稅額 77、免運；商品及門市快照、明細正確，庫存 3、清空購物車並派送事件 | `test_store_order_creates_order_details_decrements_stock_clears_cart_and_dispatches_event` |
| 小計 600，低於免運門檻 | `201`；運費 80、總額 680、內含稅額 29 | `test_store_order_adds_shipping_fee_when_items_subtotal_is_below_free_shipping_threshold` |
| 多商品以反序加入購物車；300 元購買 2、400 元購買 1 | `201`；明細依商品 ID 升冪、庫存分別扣除；小計剛好 1,000，免運、總額 1,000、稅額 48 | `test_store_order_creates_multiple_items_in_product_variant_id_order` |
| 同會員及 key 已有訂單 | `200`、原訂單 ID，不派送 OrderCreated | `test_store_order_returns_existing_order_when_idempotency_key_already_exists` |
| Repository 第一次回傳不存在，transaction 內重查回傳既有訂單 | `200`、原訂單 ID；不查商品、不新增訂單、不扣庫存、不派送事件 | `test_store_order_returns_existing_order_when_transaction_recheck_finds_order` |
| 真實空購物車 | `409`、空購物車訊息；無訂單及事件 | `test_store_order_returns_409_when_cart_is_empty` |
| 真實購物車含不存在的商品規格 | `409`、商品不存在訊息；無訂單及事件 | `test_store_order_returns_409_when_cart_contains_missing_product_variant` |
| 真實庫存 1、購買 2 | `409`、庫存不足訊息；庫存不變，無訂單及事件 | `test_store_order_returns_409_when_stock_is_insufficient` |
| 模擬 atomic decrement 回傳影響筆數 0 | `409`、「產品庫存不足。」 | `test_store_order_returns_409_when_atomic_stock_decrement_fails` |
| 模擬明細 Repository 回傳 false | `503`、通用建單失敗訊息；此局部案例只斷言回應，不以此宣稱已驗證完整 rollback | `test_store_order_returns_503_when_order_details_cannot_be_created` |
| 模擬新增時唯一性衝突，重查找到同會員／key 的訂單 | `200`、原訂單 ID，不派送 OrderCreated | `test_store_order_returns_existing_order_when_unique_constraint_collision_finds_existing_order` |
| 模擬新增時唯一性衝突，重查仍找不到同會員／key 的訂單 | 記錄含 member_id 的 error，回傳 `503`、通用建單失敗訊息 | `test_store_order_returns_503_when_unique_constraint_collision_cannot_find_existing_order` |
| 模擬非唯一索引的 QueryException | 記錄含 member_id 的 error，回傳 `503`、通用建單失敗訊息 | `test_store_order_returns_503_when_query_exception_is_not_unique_constraint_collision` |
| 模擬 checkout lock 等待逾時 | 會員專用鎖租期 10 秒、等待 3 秒；`409`、「訂單建立中，請稍後再試。」 | `test_store_order_returns_409_when_checkout_lock_times_out` |
| 模擬讀取購物車拋出非預期例外 | 記錄含 member_id 的 error，回傳 `503`、通用建單失敗訊息 | `test_store_order_returns_503_when_unexpected_exception_occurs` |

### 3. 真實 Session Cookie 與 CSRF

正式 SPA 的 Session／CSRF 規則與取消訂單一致，參見 [docs/architecture/authentication.md](../architecture/authentication.md)。目前建立訂單的單行程 Feature Test 以 `Sanctum::actingAs()` 驗證身份與業務；並行 HTTP 請求以測試專用 PAT 隔離 Session 鎖，不代表正式 SPA 使用 PAT。

目前未在 `POST /api/orders` 專屬測試中，以真實 Cookie 登入／登出及缺失／偽造 XSRF Token 完整重驗。取消訂單第 3 節的 Session／CSRF 案例只證明該 endpoint 的行為，不列為建立訂單已直接覆蓋的案例。

### 4. 真實資料庫並行測試

以下使用 MySQL、已提交的獨立 fixture connection、多個 PHP process 與明確的 SQL marker／stdin 同步閘門，不以固定 sleep 猜測請求先後。

#### 結帳與結帳

下表位於 [tests/Feature/OrderCreationConcurrencyTest.php](../../tests/Feature/OrderCreationConcurrencyTest.php)。HTTP kernel、Service、付款 Listener 與資料庫更新皆為真實流程；僅 fake 通知並禁止非預期 HTTP，計算 OrderCreated 次數但不 fake 該事件。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 同會員、同 key；共用兩商品購物車、各購買 2，初始庫存 5／7；第一請求扣庫存後暫停，第二請求確認取得 checkout lock 失敗並等待 | `201`／`200`、同一訂單；庫存更新依 ID 升冪且各一次，最終 3／5；僅一筆訂單、兩筆明細、一筆待付款交易、一次事件，購物車清空 | `test_same_member_concurrent_checkouts_consume_the_shared_cart_once`，`same member and key` |
| 相同共用購物車與交錯，但同會員使用不同 key | `201`／`409`；敗方等鎖後遇到空購物車，不執行庫存寫入、不重複建單或派送事件；庫存、明細及付款結果同上 | `test_same_member_concurrent_checkouts_consume_the_shared_cart_once`，`same member with different keys` |
| 不同會員各購買最後一件；第一方扣庫存但未提交，第二方持有自己的 checkout lock，通過讀取後進入 atomic decrement 競爭 | `201`／`409`；庫存為 0，非目標商品庫存不變；勝方只有一筆訂單、明細、待付款交易及事件，敗方無訂單或事件且購物車保留 | `test_different_members_cannot_both_purchase_the_last_item` |

使用每案例獨立目錄的 file cache，在多個 process 間共享購物車及真實 FileLock。鎖的觀察器只輸出 acquisition 結果，不替換 acquire／release 行為，不使用 process-local array cache 模擬共享鎖。這不等同 Redis backend 的故障或連線整合測試。

fixture 完成後停止子行程，刪除自身資料及 cache 目錄，不全域 flush。同會員不同 key 案例驗證「成功等鎖後遇到空購物車」，不是 process 等鎖逾時；後者僅由第 2 節 Service mock 驗證。

#### 結帳與取消回補

下表位於 [tests/Feature/OrderCancellationConcurrencyTest.php](../../tests/Feature/OrderCancellationConcurrencyTest.php)，與取消訂單第 4 節共用案例，從建單角度列出完整預期。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 取消與不同會員 checkout 共用兩商品，雙方各先完成第一筆庫存寫入 | 取消 `200`、checkout `201`；初始 10／20、各回補 2、各購買 3，最終 9／19；商品寫入依 ID 升冪，新訂單及付款交易只建立一次 | `test_cancellation_and_another_members_checkout_preserve_shared_inventory` |
| 兩商品初始庫存各 1、取消各回補 2、checkout 各購買 3；回補提交後才放行 checkout 的交易內查詢 | 取消 `200`、checkout `201`；使用已提交回補，最終庫存各 0，僅一筆新訂單、兩筆明細、一筆待付款交易 | `test_insufficient_checkout_and_cancellation_use_only_committed_inventory`，回補先提交 |
| 相同不足庫存 fixture；checkout 已讀到不足，在回補尚未提交時繼續 | checkout `409`，不寫庫存、不建立訂單、明細或付款；取消 `200`，最終庫存各 3 | `test_insufficient_checkout_and_cancellation_use_only_committed_inventory`，先讀到不足 |

這些跨取消案例以 process-local cart 準備不同會員的結帳資料，用來驗證 MySQL 商品鎖與已提交庫存，不用來證明相同會員的共享購物車或 checkout cache lock。

#### 控制案例

下表同樣位於 [tests/Feature/OrderCancellationConcurrencyTest.php](../../tests/Feature/OrderCancellationConcurrencyTest.php)，確認 process 能通過身份驗證、建立購物車並執行建單，不因測試機制失效而誤判。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 真實 checkout process，無取消競爭 | `201`，扣兩筆庫存，建立訂單、明細及付款交易 | `test_checkout_process_control_executes_the_real_order_creation_flow` |
| 交易內訂單查詢前暫停，無取消回補；庫存各 1、購買各 3 | 可正常放行；`409`，庫存及既有付款交易不變，不建立新訂單 | `test_insufficient_checkout_control_synchronizes_stock_reads_without_mutations`，交易內查詢前 |
| 庫存讀取後暫停，無取消回補；相同不足庫存 | 可正常放行；`409`，庫存及既有付款交易不變，不建立新訂單 | `test_insufficient_checkout_control_synchronizes_stock_reads_without_mutations`，庫存讀取後 |

#### Listener 局部測試

沿用專案慣例放置於 `tests/Unit/`，其中使用真實資料庫的案例嚴格分類屬 database integration test。Gateway payload 案例以 mock 驗證 Listener 職責，不代表真實供應商已收款；同步執行的案例只檢查 Listener 不實作 ShouldQueue。

| 測試檔案 | 情境 | 預期結果 | 測試方法 |
|---|---|---|---|
| [tests/Unit/CreatePaymentTransactionTest.php](../../tests/Unit/CreatePaymentTransactionTest.php) | 建立付款交易 Listener 的 queue 契約 | 不實作 ShouldQueue，避免建單後立即取得付款資料時尚未建立交易 | `test_listener_creates_payment_transaction_synchronously` |
| [tests/Unit/CreatePaymentTransactionTest.php](../../tests/Unit/CreatePaymentTransactionTest.php) | 收到 OrderCreated，訂單總額 1,280、信用卡付款 | 建立 ECPay、TWD、PENDING 交易，金額 1,280、付款方式正確，交易編號符合日期格式；三種 payload 初始為 null，派送 PaymentInitiated | `test_handle_creates_payment_transaction_and_dispatches_payment_initiated` |
| [tests/Unit/CreatePaymentTransactionTest.php](../../tests/Unit/CreatePaymentTransactionTest.php) | 同日期替兩筆訂單建立付款交易 | 兩個商店交易編號皆符合格式且不相同 | `test_handle_creates_unique_merchant_trade_no_for_each_payment_transaction` |
| [tests/Unit/CreatePaymentTransactionTest.php](../../tests/Unit/CreatePaymentTransactionTest.php) | 該訂單已有付款交易 | 仍僅一筆交易，不派送 PaymentInitiated | `test_handle_does_not_create_duplicate_payment_transaction_when_one_already_exists` |
| [tests/Unit/CreatePaymentTransactionTest.php](../../tests/Unit/CreatePaymentTransactionTest.php) | 設定預設 ECPay provider，分別以信用卡／ATM／CVS／BARCODE 建單 | 四種方式皆建立設定的 provider 與對應付款方式 | `test_handle_resolves_provider_from_default_payment_provider_config` |
| [tests/Unit/CreatePaymentTransactionTest.php](../../tests/Unit/CreatePaymentTransactionTest.php) | OrderCreated 指定不存在訂單 | 拋出 ModelNotFoundException | `test_handle_throws_model_not_found_exception_when_order_is_missing` |
| [tests/Unit/CreatePaymentTransactionTest.php](../../tests/Unit/CreatePaymentTransactionTest.php) | 已取消訂單收到延遲 OrderCreated | 不建立付款交易、不派送 PaymentInitiated，訂單不變 | `test_handle_does_not_create_payment_or_dispatch_payment_initiated_for_canceled_order` |
| [tests/Unit/BuildPaymentCheckoutPayloadTest.php](../../tests/Unit/BuildPaymentCheckoutPayloadTest.php) | 建立 payload Listener 的 queue 契約 | 不實作 ShouldQueue，付款 payload 可同步準備 | `test_listener_should_not_be_queued` |
| [tests/Unit/BuildPaymentCheckoutPayloadTest.php](../../tests/Unit/BuildPaymentCheckoutPayloadTest.php) | PaymentInitiated 指向既有交易，Gateway 回傳付款 request | 依交易 provider 取得 Gateway；寫入 request params 與 checkout action／method，response payload 保持 null，記錄準備完成的 info | `test_handle_builds_payment_request_and_checkout_payload` |
| [tests/Unit/BuildPaymentCheckoutPayloadTest.php](../../tests/Unit/BuildPaymentCheckoutPayloadTest.php) | PaymentInitiated 指定不存在交易 | 拋出 ModelNotFoundException，不呼叫 Gateway、不記錄準備完成 | `test_handle_throws_model_not_found_exception_when_payment_transaction_is_missing` |
| [tests/Unit/SendOrderCreatedNotificationTest.php](../../tests/Unit/SendOrderCreatedNotificationTest.php) | OrderCreated 指向既有訂單 | 通知訂單所屬會員，通知內容包含正確 order_id | `test_handle_sends_order_created_notification_to_order_member` |
| [tests/Unit/SendOrderCreatedNotificationTest.php](../../tests/Unit/SendOrderCreatedNotificationTest.php) | OrderCreated 指定不存在訂單 | 拋出 ModelNotFoundException | `test_handle_throws_model_not_found_exception_when_order_is_missing` |

### 5. RateLimiter

下表第一列位於 [tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)，直接呼叫 `POST /api/orders`；其餘同檔案例以取消／付款交易寫入驗證共用 `orders` limiter，不表示逐一重驗 POST 的所有組合。門檻對應 [app/Providers/AppServiceProvider.php](../../app/Providers/AppServiceProvider.php) 的設定。

| 情境 | 預期結果 | 測試方法 |
|---|---|---|
| 同會員一分鐘內以不同 key 建單，第 11 次請求 | 前 10 次 Service 呼叫映射為 `201`，第 11 次為 `429`，Service 總共僅呼叫 10 次；此案例未另斷言 Retry-After | `test_store_is_rate_limited_for_authenticated_member` |
| 共用寫入分鐘額度到期 | 第 11 次取消 `429`、資料不變，61 秒後可操作 | `test_eleventh_write_in_one_minute_is_rate_limited_without_canceling_the_target_order` |
| 訂單與付款交易寫入共用會員分鐘額度 | 跨訂單寫入共用額度，不影響讀取及其他會員 | `test_member_minute_bucket_is_shared_across_orders_and_payment_writes_but_not_reads_or_other_members` |
| 同 IP、跨會員的每小時額度 | 第 51 次寫入 `429`；其他 IP 可操作，3601 秒後原 IP 可操作 | `test_fifty_first_write_from_the_same_ip_in_one_hour_is_rate_limited_across_members` |

### 6. 尚未定義及保證範圍

- 真實 SPA Cookie 登入、登出及 CSRF 拒絕尚無 POST 專屬案例，不以取消測試替代。
- Checkout lock 等待逾時目前是 mock 分支測試；不宣稱已驗證多 process 等到逾時或超過 10 秒租期。
- 唯一性衝突重查及部分 Service 錯誤採 mock；不宣稱是請求繞過會員鎖後的真實唯一索引競爭。
- SQL rollback 案例皆在清空購物車前失敗，不表示 database rollback 會自動還原已清空的 cache。
- Redis 失聯、所有死結交錯、清空 cache 後提交失敗、提交後事件／通知失敗的補償，以及外部金流付款、對帳／退款，不在上述測試完整保證內。
- 既有驗證案例涵蓋表列輸入，不表示所有欄位型別、長度邊界或未知欄位皆有獨立案例。

### 7. 驗證紀錄

先前於 Laradock `workspace` 執行新增的 3 個並行案例及 5 個 rollback 案例，均通過。再以同一 runner 執行 [tests/Feature/OrderCreationConcurrencyTest.php](../../tests/Feature/OrderCreationConcurrencyTest.php)、[tests/Feature/OrderControllerTest.php](../../tests/Feature/OrderControllerTest.php)、[tests/Feature/OrderCancellationConcurrencyTest.php](../../tests/Feature/OrderCancellationConcurrencyTest.php)、[tests/Unit/OrderServiceTest.php](../../tests/Unit/OrderServiceTest.php)，結果為 **279 通過、0 失敗、2,516 assertions**。當時兩個異動的 PHP 測試檔案通過 Pint，未修改正式程式，未發現需保留的 Red 案例，亦未執行完整 test suite。

本次文件整理另列出既有的付款與通知 Listener 案例；它們不在上述四類別的 279 案例驗證紀錄內，不據此宣稱本次已重跑。本次僅調整文件，未重新執行 PHPUnit 或 Pint。

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
