# 訂單 API

## 訂單列表

```http
GET /api/orders
```

### 身份驗證與參數

- 需要 `auth:sanctum`，SPA 以 Session Cookie 驗證，不要求 Bearer Token。
- GET 不要求 `X-XSRF-TOKEN` header；SPA 登入等寫入操作仍須遵循既有 CSRF 流程。
- 僅查詢登入會員的訂單，query string 的 `member_id` 不會改變查詢歸屬。
- 套用 `throttle:orders` 的讀取額度，不使用寫入額度。

| Query parameter | 必要性 | 格式與限制 |
|---|---|---|
| `row_counts_per_page` | 選填 | 整數，1 至 30；省略或空值時預設 10 |
| `page` | 選填 | 整數，至少 1；省略或空值時預設 1 |

不需要 request body。非法分頁參數回傳 `422`，不自動截斷或修正成合法值。

### 可查詢條件

會員已登入且分頁參數合法。沒有訂單或請求頁碼超出末頁，皆回傳 `200` 空資料，不以 `404` 處理。

不依訂單／付款狀態或付款／配送方式排除訂單，所有既有 Enum 值皆可出現在列表。

### 回應

| HTTP status | 情境 |
|---|---|
| `200` | 成功取得列表，包含空列表及超出末頁 |
| `401` | 未登入或 Session 已失效 |
| `422` | 分頁參數驗證失敗 |
| `429` | 超過讀取限流，包含 `Retry-After` |
| `500` | 查詢失敗，回傳通用錯誤，不偽裝成空列表或暴露內部資訊 |

成功回應使用 `data`、`links`、`meta` 分頁結構，不額外包裝 `message`。每筆 `data` 僅公開以下九個摘要欄位：

| 欄位 | 型別與說明 |
|---|---|
| `id` | 整數，訂單 ID |
| `number` | 字串，訂單編號 |
| `created_at` | ISO 8601 日期時間字串；資料無建立時間時可為 null |
| `total_amount` | 整數，訂單儲存的總額 |
| `status` | 整數，訂單狀態 Enum 原值 |
| `payment_status` | 整數，付款狀態 Enum 原值 |
| `payment_method` | 整數，付款方式 Enum 原值 |
| `shipping_method` | 整數，配送方式 Enum 原值 |
| `item_quantity` | 整數，該訂單明細 quantity 加總；無明細為 0 |

不回傳收件個資、完整明細、付款 payload 或物流資料，也不將 Enum 轉為中文標籤。

- 排序為 `created_at` 降冪，再以 `id` 降冪處理同時間訂單。
- `links` 包含 `first`、`last`、`prev`、`next`；分頁 URL 保留每頁筆數等 query string，無前／後頁時對應值為 null。
- `meta` 包含 `current_page`、`from`、`last_page`、`links`、`path`、`per_page`、`to`、`total`。
- 沒有訂單時，`data` 為空陣列、`total` 為 0、`last_page` 為 1，`from`／`to` 為 null。
- 超出末頁時，保留實際 `total`、`last_page` 與請求的 `current_page`，`data` 為空陣列、`from`／`to` 為 null。

驗證失敗包含 `message` 與 `errors`；關閉 debug 時，查詢失敗為 `{"message":"Server Error"}`。

### 副作用

查詢維持唯讀，不修改訂單、明細或庫存，也不建立付款交易或物流。限流額度仍會依 middleware 計算。

商品件數須批次聚合，避免每筆訂單另查明細產生 N+1。查詢不要求結帳／取消用的 row lock，也不提供跨多次翻頁的固定資料快照。

### 限流

目前為每會員每分鐘 60 次、每 IP 每小時 500 次，與訂單詳情及其他套用同一 `orders` limiter 的 GET／HEAD 共用讀取額度，與寫入額度分離。

會員分鐘額度不因更換 IP 重置；同 IP 的其他會員有自己的分鐘額度，但共用 IP 小時額度。超限回傳 `429` 與 `Retry-After`，相應額度到期後恢復；正式數值以實際設定為準。

### 規格狀態與相關文件

訂單列表及 Session、分頁、資料隔離、唯讀、N+1 與讀取限流案例已實作。

- [業務規則與查詢範圍](../business-rules/order-rules.md)
- [測試案例與 PHPUnit 對應](../test-cases/orders.md)
- [Session／CSRF 架構](../architecture/authentication.md)

## 訂單詳情

```http
GET /api/orders/{order}
```

### 身份驗證與參數

- 需要 `auth:sanctum`，SPA 以 Session Cookie 驗證，不要求 Bearer Token。
- GET 不要求 `X-XSRF-TOKEN` header；登入與登出本身仍遵循既有 CSRF 流程。
- `{order}` 僅接受路由限定的數字格式；`0` 等不存在的數字 ID 回傳 `404`。
- 不需要 request body；query string 的 `member_id` 不得改變訂單歸屬。
- 套用 `throttle:orders` 的讀取額度。

### 可查詢條件

會員已登入且指定訂單屬於本人。訂單不存在與屬於其他會員皆回傳 `404`，不公開他人訂單內容。

所有既有訂單／付款狀態及付款／配送方式皆可查詢，包括已取消、已完成與已退款。商品或商品規格軟刪除不影響歷史明細的顯示。

### 回應

| HTTP status | 情境 |
|---|---|
| `200` | 成功取得本人訂單 |
| `401` | 未登入或 Session 已失效 |
| `404` | 路徑格式非法、訂單不存在或不屬於登入會員 |
| `429` | 超過讀取限流，包含 `Retry-After` |
| `500` | 明細或付款操作資格查詢失敗，回傳通用錯誤，不暴露內部資訊 |

成功回應只包裝 `data`，不額外加入 `message`、分頁資料、付款交易或物流紀錄：

| 欄位 | 型別與說明 |
|---|---|
| `id`、`number` | 整數訂單 ID、字串訂單編號 |
| `created_at` | ISO 8601 日期時間字串；資料無建立時間時可為 null |
| `status`、`payment_status`、`payment_method`、`shipping_method` | 整數 Enum 原值，不轉為中文標籤 |
| `item_quantity` | 整數，該訂單明細 quantity 加總 |
| `items_subtotal` | 整數，該訂單已儲存的明細 subtotal 加總，不以單價乘數量重算 |
| `shipping_fee`、`tax_amount`、`total_amount` | 整數，訂單儲存的運費、內含稅額及總額，不重新計算或重複加稅 |
| `recipient_name`、`recipient_phone`、`recipient_zip_code`、`recipient_address` | 訂單保存的收件快照，不使用會員最新資料補值；選填欄位可為 null |
| `store_type`、`store_code`、`store_name`、`store_address` | 訂單保存的超商門市快照，未提供時可為 null |
| `actions.can_create_payment_transaction` | 布林值，查詢當下是否可新增付款交易 |
| `items` | 訂單商品快照陣列 |
| `items[].product_variant_id` | 整數，商品規格 ID |
| `items[].product_name`、`product_sku`、`product_color`、`product_size` | 商品名稱、SKU、顏色與整數尺寸快照；舊資料的 SKU、顏色及尺寸可為 null |
| `items[].product_price`、`quantity`、`subtotal` | 整數，保存的單價、數量與小計 |

無明細時 `items` 為空陣列、`item_quantity` 與 `items_subtotal` 皆為整數 0，仍保留已儲存的訂單金額。商品、規格或會員資料更新後，回應仍使用原訂單快照。

`actions.can_create_payment_transaction` 僅在訂單備貨中、付款未付款或失敗，且沒有任何 PENDING／AUTHORIZED 付款交易時為 true。須檢查該訂單的所有交易，不只最新一筆；其他訂單的交易不影響資格。其餘交易狀態不單獨阻擋，訂單／付款狀態仍須符合條件。

此布林值不保證稍後 POST 建立付款交易必定成功；寫入時仍須取得訂單鎖並重新檢查資格。資格查詢失敗須回傳 `500`，不能默認為 true 或 false。

關閉 debug 時，查詢失敗為 `{"message":"Server Error"}`。非法路徑格式在登入前後皆不匹配路由而為 `404`；合法數字路徑未登入時為 `401`。

### 副作用

查詢維持唯讀，不修改訂單、明細、庫存或歷史付款交易，不重設付款失敗狀態，不建立付款／物流，也不派送建單或付款初始化事件。限流額度仍會依 middleware 計算。

明細取自已儲存的快照，不應逐筆查詢目前商品或規格而產生 N+1。GET 不取得結帳或取消使用的寫入鎖，也不保證訂單與資格查詢之間的原子快照。

### 限流

目前為每會員每分鐘 60 次、每 IP 每小時 500 次，與訂單列表等套用相同 `orders` limiter 的 GET／HEAD 共用額度，與寫入額度分離。

會員分鐘額度不因更換 IP 重置，IP 小時額度不因更換會員重置；超限回傳 `429` 與 `Retry-After`，相應額度到期後恢復。正式數值以實際設定為準。

### 規格狀態與相關文件

訂單詳情、歷史快照、付款操作資格、唯讀、N+1、Session 與限流案例已實作。

- [業務規則與查詢範圍](../business-rules/order-rules.md)
- [測試案例與 PHPUnit 對應](../test-cases/orders.md)
- [Session／CSRF 架構](../architecture/authentication.md)

目前操作資格為查詢提示，不代表庫存保留、付款授權或稍後的寫入資格保證。

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

取消功能及對應的跨流程並行防護已完成實作，Outside-in TDD 測試已進入 Green。測試案例與覆蓋範圍見 [docs/test-cases/orders.md](../test-cases/orders.md)。

- [業務規則與跨流程防禦](../business-rules/order-rules.md)
- [測試案例與 PHPUnit 對應](../test-cases/orders.md)
- [Session／CSRF 架構](../architecture/authentication.md)

取消後的外部實際付款對帳／退款尚待定義，不屬於本 API 已完成的保證。
