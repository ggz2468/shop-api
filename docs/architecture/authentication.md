# 身份驗證架構

## 1. 概述

本專案使用 Laravel Sanctum 提供 SPA 身份驗證，採用基於 Session 的身份驗證機制，搭配 Cookie 完成使用者身份驗證。

前端 SPA 與後端 Laravel API 分離，透過 Sanctum Stateful Authentication 機制維持使用者登入狀態。

本專案不使用 Sanctum Personal Access Token（Bearer Token）作為 SPA 的主要身份驗證方式。

### 1.1 身份驗證機制

| 項目 | 使用技術 |
|---|---|
| 身份驗證機制 | Laravel Sanctum |
| 身份驗證類型 | Stateful SPA Authentication |
| Session 管理 | Laravel Session |
| 身份驗證憑證 | Session Cookie |
| CSRF 防護 | Laravel CSRF Middleware |
| 前端 | Vue.js SPA |
| 後端 | Laravel RESTful API |

---

## 2. 身份驗證流程

### 2.1 初始化 CSRF Cookie

前端在執行登入操作之前，必須先向後端取得 CSRF Cookie。

**API Endpoint**

```http
GET /sanctum/csrf-cookie
```

**用途**

- 初始化 CSRF Protection 所需的 Cookie。
- 後端設定 `XSRF-TOKEN` Cookie。
- 前端 HTTP Client 必須在後續非 GET 請求中攜帶 CSRF Token。

### 2.2 會員註冊

**API Endpoint**

```http
POST /api/auth/register
```

**身份驗證**

不需要事先登入。

**請求頻率限制**

`throttle:auth-register`

**用途**

建立新的會員帳號。

### 2.3 會員登入

**API Endpoint**

```http
POST /api/auth/login
```

**身份驗證**

不需要事先登入。

**請求頻率限制**

`throttle:auth-login`

**處理流程**

1. 驗證客戶端提交的帳號密碼。
2. 使用 Laravel Authentication 驗證使用者身份。
3. 驗證成功後建立 Session。
4. 更新 Session ID（Regenerate Session ID），避免 Session Fixation。
5. 回傳登入成功的 API Response。

**預期行為**

- 登入成功：建立已驗證的 Session。
- 登入失敗：回傳適當的 HTTP Error Response。
- 登入成功後，瀏覽器保存後端設定的 Session Cookie。

### 2.4 忘記密碼

**API Endpoint**

```http
POST /api/auth/password/forgot
```

**身份驗證**

不需要事先登入。

**請求頻率限制**

`throttle:auth-password-forgot`

**用途**

透過使用者提供的電子郵件地址，發送密碼重設連結。

### 2.5 重設密碼

**API Endpoint**

```http
POST /api/auth/password/reset
```

**身份驗證**

不需要事先登入。

**請求頻率限制**

`throttle:auth-password-reset`

**用途**

透過有效的密碼重設 Token，更新使用者密碼。

### 2.6 取得目前登入會員資訊

**API Endpoint**

```http
GET /api/auth/user
```

**身份驗證**

需要有效的 Session Cookie。

**Middleware**

- `auth:sanctum`
- `throttle:auth-session`

**用途**

取得目前已登入的使用者資訊。

**預期行為**

- 已登入：回傳目前使用者資料。
- 未登入或 Session 已失效：回傳 `401 Unauthorized`。

### 2.7 會員登出

**API Endpoint**

```http
POST /api/auth/logout
```

**身份驗證**

需要有效的 Session Cookie。

**Middleware**

- `auth:sanctum`
- `throttle:auth-session`

**處理流程**

1. 執行 Laravel Logout。
2. 使目前 Session 失效（Invalidate Session）。
3. 重新產生 CSRF Token（Regenerate CSRF Token）。
4. 回傳登出成功的 API Response。

**預期行為**

- 登出成功後，原本的 Session 不再有效。
- 後續需要身份驗證的 API 請求應回傳 `401 Unauthorized`。

---

## 3. Session 管理

### 3.1 Session Cookie

本專案使用 Laravel Session 維持使用者登入狀態。

Session Cookie 相關設定由 Laravel Session Configuration 管理。

主要設定項目：

| 設定項目 | 用途 |
|---|---|
| `SESSION_DRIVER` | Session 儲存方式 |
| `SESSION_LIFETIME` | Session 有效時間 |
| `SESSION_DOMAIN` | Cookie 適用網域 |
| `SESSION_SECURE_COOKIE` | 是否僅允許 HTTPS 傳輸 |
| `SESSION_HTTP_ONLY` | 是否禁止 JavaScript 存取 Cookie |
| `SESSION_SAME_SITE` | Cookie SameSite Policy |

### 3.2 Session 安全性

- Session Cookie 應設定 `HttpOnly`，避免 JavaScript 直接存取。
- Production 環境應使用 HTTPS，並啟用 Secure Cookie。
- 登入成功後必須更新 Session ID。
- 登出時必須使 Session 失效。
- 不應將 Session ID 儲存在 Local Storage 或 Session Storage。

---

## 4. CSRF 防護

本專案使用 Laravel CSRF Protection 防止跨站請求偽造（Cross-Site Request Forgery，CSRF）攻擊。

### 4.1 CSRF Token

前端必須先呼叫：

```http
GET /sanctum/csrf-cookie
```

後端會設定 `XSRF-TOKEN` Cookie。

後續非 GET 請求必須在 HTTP Header 中帶上：

```http
X-XSRF-TOKEN: <csrf-token>
```

### 4.2 需要進行 CSRF 驗證的 HTTP Method

以下 HTTP Methods 必須符合 CSRF Token 驗證要求：

- POST
- PUT
- PATCH
- DELETE

GET、HEAD、OPTIONS 等安全方法不應用來執行具有副作用的操作。

### 4.3 CSRF 錯誤處理

若 CSRF Token 缺失或驗證失敗，後端應拒絕該請求。

預期 HTTP Status Code：

```http
419 Page Expired
```

---

## 5. CORS 設定

由於前端 SPA 與後端 API 可能使用不同 Origin，因此必須正確設定 CORS。

### 5.1 必要設定

Laravel CORS 設定必須允許：

- 前端 SPA 的 Origin。
- 攜帶憑證的請求（Credentialed Requests）。
- 必要的 HTTP Methods。
- 必要的 HTTP Headers。

使用 Cookie-based Authentication 時，必須允許 Credentials：

```http
Access-Control-Allow-Credentials: true
```

不得在允許 Credentials 的情況下使用萬用字元 `*` 作為 `Access-Control-Allow-Origin`。

### 5.2 前端 HTTP Client 設定

前端 HTTP Client 必須啟用 Credentials。

例如使用 Axios：

```javascript
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
```

---

## 6. 安全性注意事項

### 6.1 身份驗證

- 所有需要登入才能使用的 API 必須套用 `auth:sanctum` Middleware。
- 不應只依賴前端判斷使用者是否已登入。
- 後端必須自行驗證每個受保護 API 請求的身份。

### 6.2 權限驗證

身份驗證（Authentication）與權限驗證（Authorization）必須分開處理。

- Authentication：確認使用者身份。
- Authorization：確認使用者是否具有執行特定操作的權限。

即使使用者已成功登入，也必須依照業務規則檢查資源存取權限。

### 6.3 請求頻率限制

認證相關 API 必須套用適當的 Rate Limiting，避免暴力破解及惡意請求。

目前使用的 Rate Limiting：

| API | Rate Limiter |
|---|---|
| `POST /api/auth/login` | `auth-login` |
| `POST /api/auth/register` | `auth-register` |
| `POST /api/auth/password/forgot` | `auth-password-forgot` |
| `POST /api/auth/password/reset` | `auth-password-reset` |
| `POST /api/auth/logout` | `auth-session` |
| `GET /api/auth/user` | `auth-session` |
