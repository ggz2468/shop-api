# 自製電子商務網站

### 成果網站
<a href="https://chun-hung.idv.tw" target="_blank">自製電子商務網站</a>

### 開發環境
1. Linux(Debian GNU/Linux 12)
2. Nginx 1.29.8
3. MySQL 8.4.8
4. PHP 8.4.19
5. Composer 2.9.5
6. Laravel 12.x
7. Docker 29.4.0
8. Docker Compose 5.1.2

### 安裝步驟
1. 下載專案與進入目錄
```bash
git clone git@github.com:ggz2468/shop.git
cd shop
```
2. 安裝必要套件
```bash
composer install
```
3. 設定環境變數
```bash
cp .env.example .env
```
4. 啟動開發環境
```bash
docker compose up -d nginx mysql redis workspace
```
5. 初始化應用程式
```bash
docker compose exec workspace bash
php artisan key:generate
php artisan migrate --seed
```
6. API 入口網址: http://127.0.0.1/api
