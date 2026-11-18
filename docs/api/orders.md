# 訂單 API

## 建立訂單

```http
POST /api/orders
```

### 身份驗證與參數

- 需要 `auth:sanctum`，SPA 以 Session Cookie 驗證，不要求 Bearer Token。
- Stateful POST 須攜帶有效的 `X-XSRF-TOKEN`；先透過 `/sanctum/csrf-cookie` 初始化。
- 必須提供 `Idempotency-Key` header，為非空字串且最多 64 個字元；同會員重送相同建單操作時應沿用同一 key。
- 商品與數量取自登入會員的購物車；不以 request body 的 `member_id`、商品價格、庫存或訂單總額決定建單資料。
- 套用 `throttle:orders` 的寫入額度。

Request body：

| 欄位 | 必要性 | 格式與限制 |
|---|---|---|
| `payment_method` | 必填 | 整數；`1` 信用卡、`2` ATM、`3` 超商代碼、`4` 超商條碼 |
| `shipping_method` | 必填 | 整數；`1` 宅配、`2` 超商取貨 |
| `recipient_name` | 必填 | 字串，最多 50 個字元 |
| `recipient_phone` | 必填 | 字串，`09` 開頭的 10 碼數字 |
| `recipient_zip_code` | 宅配必填 | 可為 null；有值時為 3 至 6 碼數字字串 |
| `recipient_address` | 宅配必填 | 可為 null；有值時為字串，最多 500 個字元，不得以純空白滿足必填 |
| `store_type` | 超商取貨必填 | 可為 null；有值時為 `UNIMART`、`FAMI` 或 `HILIFE` |
| `store_code` | 超商取貨必填 | 可為 null；有值時為字串，最多 32 個字元 |
| `store_name` | 超商取貨必填 | 可為 null；有值時為字串，最多 50 個字元 |
| `store_address` | 超商取貨必填 | 可為 null；有值時為字串，最多 500 個字元 |

超商取貨可省略收件郵遞區號與地址。不同配送方式未要求必填的欄位，若仍提供非 null 值，也須符合其格式及長度限制。

### 可建立條件

會員已登入、參數合法、購物車非空，且購物車內所有商品規格仍存在並有足夠庫存。價格、商品資訊與可用庫存須重新從資料庫取得。

相同會員及 key 已有訂單時，依冪等規則回傳原訂單，不要求購物車仍有商品；但 HTTP 請求仍須先通過身份驗證與參數驗證。

### 回應

| HTTP status | 情境 |
|---|---|
| `201` | 成功建立新訂單 |
| `200` | 同會員的 key 已建立訂單，回傳原訂單 |
| `401` | 未登入或 Session 已失效 |
| `409` | 購物車為空、商品不存在、庫存不足或等待會員 checkout lock 逾時 |
| `419` | Stateful request 的 CSRF 驗證失敗 |
| `422` | Header 或 request body 驗證失敗 |
| `429` | 超過寫入限流，包含 `Retry-After` |
| `503` | 建單流程中的 SQL／非預期錯誤，或明細建立失敗，不暴露內部資訊 |

成功與冪等回應包含 `message`、`data`；新建為「訂單已建立。」，既有訂單為「訂單已存在。」。`data` 包含：

| 欄位 | 說明 |
|---|---|
| `id`、`number` | 訂單識別值與訂單編號，編號格式為 `ORD` + 日期 `YYYYMMDD` + 6 碼大寫英數字 |
| `total_amount`、`tax_amount`、`shipping_fee` | 訂單總額、內含稅額與運費，皆為整數金額 |
| `status`、`payment_method`、`payment_status`、`shipping_method` | 訂單、付款及配送的既有 Enum 數值 |
| `recipient_name`、`recipient_phone`、`recipient_zip_code`、`recipient_address` | 收件資料快照，選填欄位可為 null |
| `store_type`、`store_code`、`store_name`、`store_address` | 超商門市快照，選填欄位可為 null |
| `items` | 訂單商品明細陣列 |
| `items[].product_variant_id`、`product_name`、`product_sku`、`product_color`、`product_size` | 商品規格識別值與建單當下的商品快照 |
| `items[].product_price`、`quantity`、`subtotal` | 單價、購買數量及明細小計，皆為整數 |

不在此回應中附加付款 checkout payload；付款交易的 payload 由既有付款流程提供。驗證失敗回應包含 `message` 與 `errors`；業務衝突與建單失敗回應包含 `message`。

### 副作用

新建訂單為「產品備貨中／未付款」，建立商品與收件／門市快照、扣除庫存、清空購物車，並派送一次 `OrderCreated`。商品小計達 1,000 免運，未達時加收 80；稅額為商品小計內含的 5% 稅額，詳細計算見 [docs/business-rules/order-rules.md](../business-rules/order-rules.md)。

事件鏈同步建立待付款交易與付款 request／checkout payload，並通知訂單會員；建立付款 payload 不等於已付款。

同會員、相同 key 並行時，先完成者 `201`，後者等鎖後 `200` 並取得同一訂單，不重複扣庫存、建立付款交易或派送事件。同會員、不同 key 並行時，先取得 checkout lock 者 `201`；另一請求等鎖後因購物車已清空回傳 `409`，等鎖逾時亦為 `409`。

不同會員搶最後庫存時，僅庫存允許的請求成功，失敗者 `409`，不得超賣或產生負庫存。與取消回補共用商品時，兩邊皆依商品規格 ID 升冪更新，且不得預支尚未提交的回補庫存。

庫存、訂單及明細須在同一 transaction 中完成。後續商品不存在／不足，或在清空購物車前發生 SQL 失敗，已執行的資料庫異動須全部回滾，購物車保留，不派送 `OrderCreated`。

### 限流

目前為每會員每分鐘 10 次、每 IP 每小時 50 次，與取消及付款交易建立等訂單寫入共用額度，與讀取額度分離。正式數值以應用程式設定為準。

### 規格狀態與相關文件

建立功能及本文件列出的並行與 rollback 案例已實作；測試機制、局部 mock 與實際驗證範圍見 [docs/test-cases/orders.md](../test-cases/orders.md)。

- [業務規則與跨流程防禦](../business-rules/order-rules.md)
- [測試案例與 PHPUnit 對應](../test-cases/orders.md)
- [Session／CSRF 架構](../architecture/authentication.md)

資料庫 rollback 不會自動還原已清空的外部 cache，提交後事件失敗也不會自動撤銷訂單；相關補償與鎖租期逾時情境不在目前已驗證的保證範圍內。

## 取消訂單

```http
PATCH /api/orders/{order}/cancel
```

### 身份驗證與參數

- 需要 `auth:sanctum`，SPA 以 Session Cookie 驗證，不要求 Bearer Token。
- Stateful PATCH 須攜帶有效的 `X-XSRF-TOKEN`；先透過 `/sanctum/csrf-cookie` 初始化。
- `{order}` 僅接受路由限定的數字格式；`0` 等不存在的數字 ID 仍視為資源不存在。
- 不需要 request body；client 提供的 `member_id` 不得用於決定訂單歸屬。
- 套用 `throttle:orders` 的寫入額度。

### 可取消條件

本人、備貨中、付款為未付款或失敗、沒有已授權／已付款交易，且沒有任何物流紀錄。所有條件須在取得訂單鎖後重新確認。

沒有付款交易不影響資格；已退款交易不會單獨阻擋取消，歷史須保留。其他訂單的交易及物流不得影響資格。

### 回應

| HTTP status | 情境 |
|---|---|
| `200` | 成功取消，或訂單已取消而不再執行任何修改 |
| `401` | 未登入或 Session 已失效 |
| `404` | 路徑格式非法、訂單不存在或不屬於登入會員 |
| `409` | 首次取消資格不成立 |
| `419` | Stateful request 的 CSRF 驗證失敗 |
| `429` | 超過寫入限流，包含 `Retry-After` |
| `5xx` | 非預期資料庫錯誤，所有異動須回滾，不暴露內部資訊 |

成功回應包含 `message`。固定訊息文字與其他回應欄位尚未由測試約定。

路徑格式不符在登入前後皆為 `404`。合法路徑的身份驗證與 CSRF 檢查可能先於資源查詢執行。

### 副作用

訂單改為已取消、回補明細庫存且僅一次、取消所有待付款交易，保留原訂單付款狀態、金額、明細及終止交易歷史。相關異動須在同一 transaction 中完成。

多個取消請求可皆回傳 `200`，但只有一個請求實際改變資料。不發送物流請求。

不同訂單共用商品並同時取消時，各自只回補其明細數量，不能因並行更新而遺失庫存。與 checkout 並行時，checkout 不得使用尚未提交的回補；已讀到庫存不足可回傳 `409`，不保證自動等待或重試。回補提交後才開始交易內查詢的 checkout，則依回補後庫存判斷是否可購買。詳細先後條件見 [docs/business-rules/order-rules.md](../business-rules/order-rules.md)。

付款回呼、首次付款交易建立及物流建立／送單／回呼與取消共用訂單 row lock，等待鎖後須重新確認狀態。物流送單及通知在來源 transaction 提交後才排入 queue，避免回滾後仍送單或讀取尚未提交的資料。

### 限流

目前為每會員每分鐘 10 次、每 IP 每小時 50 次，與其他訂單寫入共用額度，與讀取額度分離。正式數值以應用程式設定為準。

### 規格狀態與相關文件

取消功能及對應的跨流程並行防護已完成實作，Outside-in TDD 測試已進入 Green。測試執行紀錄見 [docs/test-cases/orders.md](../test-cases/orders.md)。

- [業務規則與跨流程防禦](../business-rules/order-rules.md)
- [測試案例與 PHPUnit 對應](../test-cases/orders.md)
- [Session／CSRF 架構](../architecture/authentication.md)

取消後的外部實際付款對帳／退款尚待定義，不屬於本 API 已完成的保證。
