# 訂單 API

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
