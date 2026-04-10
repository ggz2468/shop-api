# 自製電子商務網站

### 成果網站
<a href="https://chun-hung.idv.tw" target="_blank">自製電子商務網站</a>

### 開發環境
1. Linux(Debian GNU/Linux 12)
2. nginx 1.29.8
3. MySQL 8.4.8
4. PHP 8.4.19
5. Composer 2.9.5
6. Laravel 12.x
7. Docker 29.4.0
8. Docker Compose v5.1.2

### 安裝步驟
1. 下載此專案程式，並切換至專案目錄內
```bash
git clone git@github.com:ggz2468/shop.git
cd shop
```
2. 安裝此專案所需的 Composer 套件
```bash
composer install
```
3. 將 .env.example 複製為 .env
```bash
cp .env.example .env
```
4. 填入所需屬性值至 .env
```
# 專案名稱
APP_NAME=自製電子商務網站

# 網站網址
APP_URL=

# 資料庫設定值
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

# Redis 設定值
REDIS_CLIENT=
REDIS_HOST=
REDIS_PASSWORD=
REDIS_PORT=
```
5. 產生應用程式密鑰
```
php artisan key:generate
```
6. 進行資料庫遷移，並寫入測試資料
```
php artisan migrate --seed
```
7. 啟動伺服器
```
php artisan serve
```
8. 開啟瀏覽器並前往: <a href="http://127.0.0.1:8000" target="_blank">http://127.0.0.1:8000</a>
