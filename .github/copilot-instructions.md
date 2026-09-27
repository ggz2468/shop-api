# shop-api GitHub Copilot 開發規範

## 1. 專案概述

本專案是一個使用 Laravel 開發的 RESTful API 電商系統。

主要技術：

- PHP 8.4
- Laravel 12
- MySQL
- Redis
- Laravel Sanctum
- PHPUnit 11
- Laravel Pint
- Docker / Laradock
- Nginx

本專案以 API-first 為主要設計。

在實作任何功能之前，應先理解目前專案的實際架構與既有程式碼，再進行修改。

> 最重要的原則：**優先遵循目前專案已存在的架構與慣例，而不是直接套用一般 Laravel 教學或通用最佳實務。**

不要因為一般 Laravel 專案通常採用某種做法，就任意改變本專案目前已建立的架構。

---

# 2. 指令執行環境

## 2.1 PHP / Composer / Artisan 執行位置

本專案禁止直接在 Host Environment 執行以下指令：

- `php`
- `composer`
- `artisan`

PHP 與 Composer 相關工具預期在 Laradock 的 `workspace` container 中執行。

如果目前 VS Code session 尚未 Attach 到 Laradock container，請透過 Laradock `workspace` container 執行專案指令。

標準執行方式：

```bash
cd ../laradock && docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && <command>'
```

例如執行 PHPUnit：

```bash
cd ../laradock && docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && php artisan test'
```

例如執行 Composer：

```bash
cd ../laradock && docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && composer install'
```

如果 Laradock 位於不同位置，請依實際 Host Path 調整 `cd ../laradock`。

## 2.2 已確認的環境資訊

目前 Laradock 將 parent projects directory mount 至：

```text
/var/www
```

因此本 repository 在 container 內的路徑為：

```text
/var/www/shop-api
```

`workspace` service 是本專案執行以下工具的預期環境：

- PHP
- Composer
- Artisan
- PHPUnit
- Laravel Pint

除非目前 VS Code session 已經 Attach 到正確的 container，否則不要假設 Host Environment 已安裝或設定正確的 PHP / Composer / Laravel CLI。

---

# 3. 核心開發原則

在修改程式碼之前，請先檢查與需求相關的既有程式碼。

至少應視需求檢查：

1. 相關 Route。
2. 相關 Controller。
3. 相關 Service。
4. 相關 Repository。
5. 相關 Store。
6. 相關 Model。
7. 相關 Enum。
8. 相關 Event / Job。
9. 相關 Migration。
10. 相關 PHPUnit Test。
11. 現有 API Request / Response 格式。

如果專案中已經存在可以重複使用的實作，應優先使用，而不是建立另一套類似功能。

不要：

- 任意建立新的 Design Pattern。
- 任意新增第三方套件。
- 任意新增 Repository / Service / Store。
- 任意改變既有 API contract。
- 修改與目前需求無關的檔案。
- 因為「看起來可以更漂亮」而重構無關程式碼。

---

# 4. 專案架構

目前專案主要採用以下分層：

```text
HTTP Request
    ↓
Controller
    ↓
Service
    ↓
Repository / Store
    ↓
Model / Database / Redis
```

部分功能可能包含：

```text
Service
 ├── Repository
 ├── Store
 ├── Event
 ├── Job
 └── External Service
```

實作新功能時，應優先遵循目前專案已有的分層方式。

---

# 5. Controller

Controller 應保持精簡。

Controller 主要負責：

- 接收 HTTP Request。
- 使用既有的 validation 機制。
- 取得 authenticated user / member。
- 呼叫適當的 Service。
- 將 Service 結果轉換為 HTTP Response。
- 維持既有 API response 格式。

不要在 Controller 中放置大量 business logic。

不要在 Controller 中直接執行複雜的 database 操作。

不要在多個 Controller 中重複相同的 business logic。

如果已有適當的 Service，應使用該 Service，而不是在 Controller 中重新實作。

---

# 6. Service

Service 負責 application logic 與 business logic。

適合放入 Service 的內容包括：

- Order 建立。
- Cart business operations。
- Payment processing。
- Shipment processing。
- External service integration。
- 跨多個 Repository / Model 的 business operation。
- Database transaction。
- Concurrency-sensitive operation。
- 複雜的 business rule。

建立新的 Service 前，應先搜尋是否已有負責相同 business domain 的 Service。

不要為了簡單的程式碼而過度抽象。

Service 應維持清楚的 business responsibility。

---

# 7. Repository

本專案使用 Repository 封裝部分 database access。

如果某個 Model 或 business operation 已經存在對應 Repository：

- 優先使用既有 Repository。
- 將可重複使用的 database operation 放入適當 Repository。
- 避免在不同 Service 中重複相同 query。
- 不要讓 Controller 直接處理複雜 database query。
- 不要為每一個 Model 強制建立 Repository。

建立新的 Repository 前，必須確認目前架構確實需要。

如果涉及 concurrency-sensitive database update，應優先使用 atomic database operation。

例如 inventory 更新應維持類似：

```php
where('stock_quantity', '>=', $quantity)
    ->decrement('stock_quantity', $quantity);
```

而不是改成不安全的 read-then-write：

```php
if ($productVariant->stock_quantity >= $quantity) {
    $productVariant->stock_quantity -= $quantity;
    $productVariant->save();
}
```

---

# 8. Store

本專案使用 Store 封裝 Redis 等外部 state storage。

例如：

```text
CartStore
```

當需要操作 Redis-backed state 時：

- 優先使用既有 Store。
- 遵循既有 Redis key naming convention。
- 遵循既有 TTL。
- 處理 Redis operation failure。
- 遵循既有 logging strategy。
- 不要從 Controller 直接操作 Redis。
- 不要為相同 state 建立另一套 storage abstraction。

Redis 不應被視為商品價格、庫存或其他 authoritative business data 的唯一來源。

---

# 9. Laravel 開發規範

使用 Laravel 12 提供的功能與 API，除非目前專案已有不同的既有實作。

優先使用：

- Dependency Injection
- Constructor Property Promotion
- Eloquent Relationships
- Query Builder
- Form Request
- Database Transaction
- Laravel Events
- Laravel Jobs
- Laravel Cache / Redis abstraction

不要因為一般 Laravel 教學推薦某種架構，就覆蓋目前專案既有的架構。

新增第三方 package 前，必須先確認：

1. Laravel 是否已經提供相同功能。
2. 專案是否已有相同功能。
3. 是否真的需要第三方 package。
4. Package 是否支援目前 PHP / Laravel 版本。

---

# 10. API 設計

本專案是 RESTful API。

新增或修改 API 時：

1. 先檢查 `routes/api.php`。
2. 找到相關 Controller。
3. 檢查相似 API 的實作。
4. 遵循既有 authentication middleware。
5. 遵循既有 throttle middleware。
6. 維持既有 HTTP method。
7. 維持既有 URL convention。
8. 維持既有 response structure。
9. 補上或修改相關 tests。

除非需求明確要求，否則不要任意修改既有 API：

- HTTP method
- URL
- middleware
- authentication requirement
- response format
- field name
- HTTP status code

---

# 11. Validation

所有來自 client 的資料都必須視為不可信。

應驗證：

- Request parameters
- Path parameters
- Query parameters
- JSON body
- External callback payload

不要信任 client 提供的：

- Product price
- Order subtotal
- Order total
- Inventory quantity
- Payment status
- Order status
- User / Member ID
- Authorization-related values

需要 authoritative data 時，應從：

- authenticated user context
- database
- trusted internal service

取得。

---

# 12. Authentication 與 Authorization

本專案使用 Laravel Sanctum。

需要 authenticated user 的 API：

- 使用既有 `auth:sanctum` middleware。
- 從 authenticated request context 取得 member/user。
- 不要信任 client 提供的 member/user ID。
- 修改 user-specific resource 前確認 resource ownership。

不要為了方便實作而移除 authentication 或 authorization。

如果修改 authorization 行為，必須先檢查：

- Middleware
- Policy
- Controller
- Service
- Existing tests

---

# 13. Order

Order 是本專案最重要的 business domain 之一，而且具有高度 concurrency sensitivity。

目前 Order 建立流程包含：

- `Idempotency-Key`
- Member checkout lock
- Redis / Cache lock
- Database transaction
- Inventory validation
- Atomic inventory decrement
- Order creation
- Order detail creation
- Cart clearing
- `OrderCreated` event
- Duplicate request handling
- Error logging

修改 Order creation flow 時，必須維持上述 correctness guarantees。

---

# 14. Idempotency

Order creation 使用 `Idempotency-Key`。

不要移除或弱化 idempotency mechanism。

處理 idempotent request 時：

1. 確認該 member 是否已使用相同 key 建立 Order。
2. 如果已存在，依既有規則回傳原本的 Order。
3. 正確處理 database unique constraint race condition。
4. 不應建立 duplicate Order。

不要認為：

```text
先 SELECT 檢查是否存在
        ↓
不存在
        ↓
INSERT
```

就足以保證 idempotency。

在 concurrent requests 下仍可能發生 race condition。

Database uniqueness constraint 是 correctness mechanism 的一部分。

---

# 15. Checkout Concurrency

Checkout 是 concurrency-sensitive operation。

修改 checkout flow 時：

- 必須保留 member checkout lock。
- 必須保留 database transaction。
- 必須保留 atomic inventory update。
- 不可使用不安全的 read-then-write inventory update。
- 注意 lock ordering。
- 避免 deadlock。
- 確保 transaction rollback 後資料一致。

每次修改 checkout flow 時，都應思考：

```text
兩個 request 同時 checkout
        ↓
是否可能建立兩個 Order？
        ↓
是否可能重複扣庫存？
        ↓
是否可能 oversell？
        ↓
是否可能產生 deadlock？
        ↓
是否可能留下不一致資料？
```

---

# 16. Inventory

Inventory 是 concurrency-sensitive data。

不得讓 inventory 變成負數。

不得信任 client 提供的 inventory quantity。

Inventory 更新應使用 atomic database operation。

例如：

```php
where('stock_quantity', '>=', $quantity)
    ->decrement('stock_quantity', $quantity);
```

不要將 atomic operation 改成：

```php
$productVariant->stock_quantity -= $quantity;
$productVariant->save();
```

除非能證明新的實作具有完全等價的 concurrency safety。

---

# 17. Database Transaction

以下情況應考慮使用 database transaction：

- 建立 Order。
- 建立 Order details。
- 修改 Inventory 並建立相關 records。
- Payment state transition。
- Shipment state transition。
- 多個 database records 必須同時成功或失敗的 operation。

如果 operation 中包含多個 database modifications，而這些操作必須 atomic，就應放入適當的 transaction。

不要吞掉 transaction exception。

不要在 transaction rollback 後假設 Redis state 或 external state 已自動 rollback。

---

# 18. Redis

Redis 用於：

- Cart state
- Checkout locking
- Cache
- 其他 application state

使用 Redis 時：

- 遵循既有 key naming convention。
- 遵循既有 TTL。
- 處理 Redis failure。
- 適當記錄 Redis operation failure。
- 不要把 Redis 當成 authoritative inventory。
- 不要把 Redis 中的 cart price 視為 authoritative price。

Checkout 時，Product price、inventory 等重要資料應重新從 database 取得。

---

# 19. Cart

Cart 透過 `CartStore` 管理。

修改 Cart 時：

- 優先使用 `CartStore`。
- 不要在 Controller 直接操作 Redis。
- 遵循現有 cart data structure。
- 遵循既有 TTL。
- 驗證 Product Variant。
- 驗證 quantity。
- 處理 Redis write failure。
- Checkout 時重新取得 authoritative product data。

Cart 中儲存的 price 不應直接作為 Order price 的最終來源。

Order 建立時應重新從 database 取得商品價格。

---

# 20. Payment

Payment 是 business-critical operation。

修改 Payment 時：

1. 先檢查 Payment Service。
2. 檢查 Payment Controller。
3. 檢查 Payment Model。
4. 檢查相關 Enum。
5. 檢查相關 tests。
6. 檢查所有 payment status transition。

不要：

- 信任 client 提供的 payment status。
- 因為 callback endpoint 被呼叫就直接視為付款成功。
- 允許同一付款被重複處理。
- 任意修改 payment status transition。

External payment callback 必須經過適當的 authenticity verification。

---

# 21. ECPay

本專案整合 ECPay。

相關 callback 包含：

- Payment callback
- Shipment callback
- Shipment store-map callback

Callback Controller 應保持精簡。

Controller 應將主要處理交給適當的 Service。

修改 ECPay callback 時，必須先檢查：

1. Request payload。
2. Signature / CheckMacValue 等驗證機制。
3. Callback Service。
4. Event / Status resolution。
5. Duplicate callback handling。
6. Logging。
7. ECPay response format。
8. Existing tests。

不要假設 ECPay 每次都會傳送所有歷史上曾出現過的欄位。

External callback field 可能：

- 不存在。
- 為 null。
- 使用不同狀態。
- 在特定情境才出現。

因此處理 callback 時必須 defensive。

---

# 22. External Callback

External callback 可能：

- 重複送出。
- Out-of-order。
- Retry。
- 延遲送達。
- 缺少 optional field。
- 包含未知 status。
- 在內部狀態已經改變後才送達。

因此 callback processing 應盡可能：

- Idempotent。
- Defensive。
- 可安全 retry。
- 正確處理 duplicate callback。
- 正確處理 unknown status。
- 記錄足夠 debugging context。

不要因為 optional external field 不存在就讓 callback 整個 crash。

---

# 23. Enum

本專案使用 PHP Enum 管理 domain values，例如：

- Order status
- Payment status
- Payment method
- Shipping method
- Store type

應優先使用既有 Enum。

不要在 business logic 中建立 magic string 或 magic number。

新增 Enum value 前，必須搜尋：

1. 所有 Enum 使用位置。
2. Database cast。
3. Database constraint。
4. API response。
5. Business logic。
6. State transition。
7. Tests。

確保新增 value 不會破壞既有流程。

---

# 24. Money

Money 是 financial data。

不要使用 floating-point 進行重要金額計算，除非現有架構明確如此設計。

遵循專案現有的金額儲存方式。

Order amount 應根據 authoritative database data 計算。

不要信任 client 提供的：

```text
price
subtotal
shipping fee
total
payment amount
```

修改金額計算時，必須檢查：

- Product price
- Subtotal
- Shipping fee
- Total
- Payment amount
- Order details
- Payment integration
- Tests

---

# 25. Event / Job

本專案使用 Event / Job 處理部分 application side effects。

例如：

```text
OrderCreated
```

新增 side effect 前，先確認是否已經存在適當的 Event / Job。

避免：

- 重複 dispatch 相同 business event。
- 在 Controller 中直接執行長時間 operation。
- 建立與現有 Event / Job 重複的功能。

如果 listener 依賴 transaction commit 後的 database state，必須考慮 dispatch timing。

---

# 26. Error Handling

遵循既有 API error response 格式。

適當使用 HTTP status code，例如：

- `400`：Request / business validation error
- `401`：Unauthenticated
- `403`：Unauthorized
- `404`：Resource not found
- `409`：Conflict，例如 duplicate request、inventory conflict、state conflict
- `500`：Unexpected server error
- `503`：Dependent service unavailable，依既有 API contract 使用

不要將以下資訊回傳給 client：

- Stack trace
- SQL
- Internal exception details
- API secret
- Payment credential
- Provider credential

詳細 debugging information 應透過 logging 處理。

---

# 27. Logging

使用 Laravel / PSR logging。

Unexpected failure 應記錄足夠的 context，例如：

- member ID
- order ID
- order number
- idempotency key
- product variant ID
- ECPay identifier

但是禁止記錄：

- Password
- Authentication token
- API secret
- Payment credential
- 不必要的完整個人敏感資訊

Logging 不應取代 error handling。

---

# 28. Database

Database schema 修改必須透過 Migration。

新增或修改 schema 前：

1. 檢查現有 migrations。
2. 檢查 Model relationship。
3. 檢查 foreign key。
4. 檢查 unique constraint。
5. 檢查 index。
6. 檢查 query pattern。
7. 檢查相關 tests。

不要為了讓程式比較容易實作而移除既有 database constraint。

Database constraint 是 application correctness 的重要防線。

---

# 29. Query Performance

避免：

- N+1 Query。
- Loop 中反覆查詢 database。
- 不必要的 `SELECT *`。
- 沒有 index 的大量資料查詢。
- 一次載入過多資料到 memory。

需要 relationship data 時，考慮使用 eager loading。

修改 query 時必須確認：

- 回傳資料仍然正確。
- API response 不變。
- 不會產生 N+1。
- 不會造成明顯 performance regression。

---

# 30. API Response Contract

既有 API response structure 視為 application contract。

不要任意修改：

- field name
- `message`
- `data`
- ID format
- Enum value
- Nested structure
- HTTP status code

不要因為 Model 中有其他欄位，就任意加入 API response。

如果需求需要修改 API contract：

1. 明確確認需求。
2. 搜尋所有使用該 API 的地方。
3. 檢查現有 tests。
4. 確認是否會影響其他 API。
5. 再進行修改。

---

# 31. Testing

本專案使用 PHPUnit。

主要測試目錄：

```text
tests/Unit
tests/Feature
```

執行測試：

```bash
php artisan test
```

或：

```bash
composer test
```

**注意：以上指令必須依照「第 2 節：指令執行環境」的規則，在 Laradock `workspace` container 中執行，不得直接在 Host Environment 執行。**

新增功能時，應依需求補上適當測試。

至少考慮：

1. Happy path。
2. Validation failure。
3. Authentication failure。
4. Authorization failure。
5. Resource not found。
6. Business rule failure。
7. Duplicate request。
8. Idempotency。
9. External callback edge cases。
10. Transaction rollback。
11. Concurrency-sensitive behavior。

不要為了讓測試通過而修改測試來配合錯誤實作。

測試應驗證真正的 business behavior。

---

# 32. 測試流程

修改重要功能之前：

1. 先閱讀相關 tests。
2. 了解目前測試命名與 structure。
3. 優先執行相關測試。
4. 修改程式碼。
5. 新增或修改測試。
6. 執行相關 tests。
7. 視需求執行完整 test suite。

例如：

```bash
cd ../laradock && docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && php artisan test'
```

如果沒有實際執行測試，不可以聲稱「測試已通過」。

---

# 33. Code Style

遵循：

- PSR-12
- Laravel coding conventions
- 專案現有 coding style

本專案使用 Laravel Pint。

檢查：

```bash
cd ../laradock && docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && ./vendor/bin/pint --test'
```

必要時執行：

```bash
cd ../laradock && docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && ./vendor/bin/pint'
```

不要因為修改一行程式碼而對無關程式碼進行大量 formatting。

---

# 34. Static Analysis

如果 repository 中存在 static analysis tooling，應配合執行。

完成重要修改後應檢查：

- PHPUnit
- Laravel Pint
- Static analysis
- CI configuration

除非 VS Code session 已經 Attach 到正確的 container，否則所有 PHP / Composer / Artisan tooling 都必須依第 2 節規則透過 `workspace` container 執行。

不要只確保 happy path 可以執行，也要注意：

- Type correctness
- Nullable values
- Exception handling
- Edge cases

---

# 35. 命名

遵循現有 domain terminology。

例如：

```text
OrderService
OrderRepository
ProductVariantRepository
CartStore
EcpayShipmentCallbackService
```

不要任意替換 domain terminology。

不要建立：

```text
OrderManager
OrderHandler
OrderProcessor
```

來重複代表已存在的 `OrderService`，除非需求真的需要新的不同 responsibility。

---

# 36. Comments

Comment 應解釋「為什麼」，而不是重複「程式在做什麼」。

適合加入 Comment 的情況：

- Concurrency consideration。
- Lock ordering。
- External provider 特殊行為。
- Business rule。
- Transaction requirement。
- Idempotency requirement。
- 不容易從程式碼看出的限制。

避免加入沒有實際價值的 Comment。

---

# 37. Security

所有 HTTP Request 與 external callback 都必須視為 untrusted input。

特別注意：

- Authentication
- Authorization
- Input validation
- Mass assignment
- SQL injection
- IDOR
- Sensitive information exposure
- Webhook authenticity
- Replay attack / duplicate callback
- Race condition
- Inventory overselling
- Payment state manipulation

絕對不要信任 client 提供的：

```text
price
total
payment status
order status
inventory
member ID
authorization state
```

---

# 38. Rate Limiting

本專案已有 route-specific throttle。

新增 API 時，應先檢查現有 throttle convention。

對可能被大量呼叫或濫用的 endpoint，應使用適當的 throttle。

不要任意移除既有 throttle。

---

# 39. Concurrency

本專案是電商 API，因此 concurrency correctness 非常重要。

修改以下功能時，必須特別檢查 concurrency：

- Order
- Inventory
- Cart
- Payment
- Shipment
- Idempotency
- Redis lock
- Callback

至少思考：

```text
兩個 request 同時進入
        ↓
是否建立 duplicate record？
        ↓
是否重複扣庫存？
        ↓
是否 oversell？
        ↓
是否重複付款？
        ↓
是否重複處理 callback？
        ↓
是否 deadlock？
        ↓
是否留下 inconsistent state？
```

不要假設 HTTP request 會依序執行。

---

# 40. Git 與修改範圍

每次修改應保持 focused。

不要：

- 重構無關程式碼。
- 任意重新命名 class。
- 任意移動檔案。
- 升級 dependency。
- 修改不相關 configuration。
- 修改無關 API。
- 修改無關 database schema。

如果一個需求只需要修改兩個檔案，不應順便修改十個無關檔案。

---

# 41. Copilot 工作流程

處理非 trivial feature 時，遵循以下流程。

## Step 1：理解

先搜尋相關：

- Routes
- Controllers
- Services
- Repositories
- Stores
- Models
- Enums
- Migrations
- Events
- Jobs
- Tests

## Step 2：分析

在大量修改之前，先確認：

- 目前 architecture。
- 相關 business rules。
- 哪些現有 class 可以重複使用。
- 哪些 API contract 必須保持。
- 是否涉及 transaction。
- 是否涉及 concurrency。
- 是否涉及 idempotency。
- 是否涉及 external callback。
- 需要增加哪些 tests。

## Step 3：實作

遵循：

> 最小必要修改。

優先修改既有 class。

不要在沒有必要的情況下新增 architecture。

## Step 4：測試

新增或修改適當 PHPUnit tests。

執行相關 tests。

所有 PHP / Composer / Artisan 指令必須依照第 2 節的 Laradock `workspace` 規則執行。

## Step 5：Review

完成後檢查：

- Correctness
- Security
- API compatibility
- Transaction
- Concurrency
- Idempotency
- Error handling
- Query performance
- Test coverage
- Unrelated changes

---

# 42. 不可假設不存在的程式結構

不要假設某個 class、file、service、repository、event、job、policy、middleware 或 package 一定存在。

在引用之前：

1. Search repository。
2. 確認實際檔案位置。
3. 閱讀實際 implementation。

如果不存在，再判斷是否真的需要建立。

不要因為沒有找到原本想像中的 class，就直接建立一套新的架構。

---

# 43. 遇到不明確需求時

如果需求資訊不足，而且可能影響 correctness：

- 不要自行猜測 business rule。
- 不要自行猜測 API contract。
- 不要自行修改既有 behavior。
- 應先指出需要確認的部分。

如果有多種合理實作方式：

1. 優先選擇符合目前專案架構的方式。
2. 優先選擇修改範圍最小的方式。
3. 優先使用 Laravel 原生能力。
4. 維持既有 API contract。
5. 維持既有 transaction / concurrency / idempotency guarantees。

---

# 44. 不要自動「改善」既有程式碼

如果現有程式碼看起來與一般 Laravel best practice 不同：

> 不要直接將它視為錯誤並重構。

先確認：

1. 是否有其他程式碼依賴這個行為。
2. 是否為特定 business requirement。
3. 是否有相關 tests。
4. 是否有 concurrency / external integration 的考量。

除非需求明確要求，否則不要順便重構無關程式碼。

---

# 45. 最終檢查清單

完成任何重要功能後，確認：

- [ ] 已遵循目前專案 architecture。
- [ ] 沒有不必要的新 abstraction。
- [ ] Controller 保持精簡。
- [ ] Business logic 位於適當 Service。
- [ ] 已重複使用既有 Repository / Store。
- [ ] Client input 已經過 validation。
- [ ] Authentication / Authorization 沒有被削弱。
- [ ] API response contract 沒有被任意改變。
- [ ] Database transaction 正確。
- [ ] Concurrency 已被考慮。
- [ ] Inventory update 是 atomic。
- [ ] Idempotency 沒有被破壞。
- [ ] Redis failure 有適當處理。
- [ ] External callback 被視為 untrusted input。
- [ ] Duplicate callback 可以安全處理。
- [ ] Error logging 適當。
- [ ] 沒有暴露敏感資訊。
- [ ] PHPUnit tests 已新增或更新。
- [ ] Relevant tests 已實際執行。
- [ ] Laravel Pint 已檢查。
- [ ] 所有 PHP / Composer / Artisan 指令均在 Laradock `workspace` container 中執行。
- [ ] 沒有修改無關檔案。
- [ ] 沒有任意新增 package。
- [ ] 沒有任意改變既有 API。
- [ ] 沒有任意改變既有 database constraint。
