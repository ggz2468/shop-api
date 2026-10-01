# 自製電子商務 RESTful API 專案

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

### 安裝步驟(請先自行安裝 Laradock)
1. 下載專案
```bash
git clone git@github.com:ggz2468/shop-api.git
```
2. 切換至 Laradock 目錄，先完成設定
```bash
cd laradock/
```
請先在 Host 的 hosts 檔案加入下列對應，讓瀏覽器可透過 Nginx 使用 `shop.test`：

```text
127.0.0.1 shop.test
```

在這個目錄下，請建立或編輯以下檔案；如果檔案已存在，可以直接修改，不需要先 `touch`。

#### laradock/workspace/crontab/laradock
```cron
* * * * * laradock /usr/bin/php /var/www/shop-api/artisan schedule:run >> /dev/null 2>&1
```
#### laradock/php-worker/supervisord.d/shop-worker.conf
```ini
[program:shop-worker]
process_name=%(program_name)s_%(process_num)02d
command=/bin/sh -c "cd /var/www/shop-api && getent hosts ${REDIS_HOST:-redis} >/dev/null 2>&1 && exec php artisan queue:work redis --sleep=3 --tries=3 --timeout=120 --queue=default --backoff=5 --max-time=3600"
directory=/var/www/shop-api
autostart=true
autorestart=true
numprocs=1
user=laradock
redirect_stderr=true
stdout_logfile=/var/www/shop-api/storage/logs/worker.log
stdout_logfile_maxbytes=20MB
stdout_logfile_backups=10
stopasgroup=true
killasgroup=true
stopwaitsecs=3600
```
#### laradock/nginx/sites/shop.conf
```nginx
server {

    listen 80;
    listen [::]:80;

    server_name shop.test;
    root /var/www/shop-api/public;
    index index.php index.html;

    location / {
        proxy_pass http://host.docker.internal:5173;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_cache_bypass $http_upgrade;
    }

    location = /api {
        return 301 /api/;
    }

    location ^~ /api/ {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ^~ /storage/ {
        alias /var/www/shop-api/public/storage/;
        try_files $uri =404;
        access_log off;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000";
    }

    location = /sanctum/csrf-cookie {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php-upstream;
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ \.php$ {
        return 404;
    }
}
```
#### laradock/nginx/sites/shop-performance.conf
```nginx
server {

    listen 8888;
    listen [::]:8888;

    server_name shop.test;
    root /var/www/shop-api/public;
    index index.php index.html;

    location = /api {
        return 301 /api/;
    }

    location ^~ /api/ {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /sanctum/csrf-cookie {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ^~ /storage/ {
        alias /var/www/shop-api/public/storage/;
        try_files $uri =404;
        access_log off;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000";
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php-upstream;
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        # Laravel reads .env.performance when APP_ENV=performance.
        fastcgi_param APP_ENV performance;
        include fastcgi_params;
    }

    location ~ \.php$ {
        return 404;
    }

    access_log /var/log/nginx/shop-performance_access.log;
    error_log /var/log/nginx/shop-performance_error.log;
}
```
如果需要壓力測試，再把 `docker-compose.yml` 的 nginx ports 加上 `8888:8888`。
完成後回到 Laradock 目錄，用一個指令啟動或重建服務：

```bash
docker compose up -d --force-recreate nginx mysql redis workspace php-worker
```
3. 啟動前端專案，讓 Nginx 可將 `http://shop.test/` 的非 API 請求轉送到 Vite 開發伺服器。
```bash
cd /var/www/shop-web
npm install
npm run dev
```
4. 進入 Workspace 容器內
```bash
docker compose exec --user=laradock workspace bash
```
5. 切換至專案目錄
```bash
cd /var/www/shop-api
```
6. 安裝必要套件並建立環境檔案
```bash
composer install
cp .env.example .env
cp .env.performance.example .env.performance
cp .env.testing.example .env.testing
```
7. 初始化應用程式
```bash
php artisan key:generate
php artisan migrate --seed
```
8. 產生 `.env.performance` 與 `.env.testing` 的 APP_KEY，並分別貼回對應檔案中
```bash
php artisan key:generate --show --env=performance
php artisan key:generate --show --env=testing
```
9. 執行 product_view_counts 資料表的 Partition 維護
```bash
php artisan app:maintain-product-view-counts-partitions
```
10. 建立 storage 軟連結
```bash
php artisan storage:link
```

### 測試方式
本專案的 PHP / Composer / Artisan 指令請在 Laradock `workspace` 容器內執行。

```bash
cd ../laradock
docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && <command>'
```

#### Unit Test
Unit Test 主要驗證 `app/Services`、`app/Repositories` 等可獨立測試的邏輯。

```bash
cd ../laradock
docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && php artisan test --testsuite=Unit --env=testing'
```

如果要執行整體測試，也可以直接使用 Composer Script：

```bash
cd ../laradock
docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && composer test'
```

#### Feature Test
Feature Test 主要驗證 API 端點、Middleware、認證流程與資料庫互動。

```bash
cd ../laradock
docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && php artisan test --testsuite=Feature --env=testing'
```

#### 壓力測試（k6）
壓力測試使用 `tests/k6/` 內的腳本，搭配 `.env.performance` 與獨立的 performance 資料庫。

1. 先確認已完成 README 前面的 `shop-performance.conf` 設定、`8888:8888` Port 映射，以及 `.env.performance` 檔案建立。
2. 重新建立並灌入壓測資料：

```bash
cd ../laradock
docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && bash tests/k6/prepare-performance-data.sh'
```

3. 執行 k6 壓測腳本（請先確認執行環境已安裝 k6 CLI）：

```bash
BASE_URL=http://shop.test:8888 TEST_DURATION=2m k6 run {SCRIPT_PATH}
```

4. 若要模擬吞吐量超過限制的情境，可加上：

```bash
ALLOW_THROTTLE=true TARGET_RPS=5 BASE_URL=http://shop.test:8888 TEST_DURATION=2m k6 run {SCRIPT_PATH}
```

5. 壓測完成後清理資料：

```bash
cd ../laradock
docker compose exec --user=laradock workspace bash -lc 'cd /var/www/shop-api && bash tests/k6/cleanup-performance-data.sh'
```

#### CI 內的測試
GitHub Actions 會在 `main` 分支的推送與 Pull Request 時執行 Unit / Feature Tests，並產生 coverage 給 SonarQube 使用。

### API 入口網址
<a href="http://shop.test/api">http://shop.test/api</a>
