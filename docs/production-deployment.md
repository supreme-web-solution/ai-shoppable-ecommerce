# Production Deployment Guide

This guide covers deploying the AI Social Video Commerce platform with **Laravel Horizon**, **Reverb**, **Redis**, and **MySQL**.

---

## Prerequisites

- PHP 8.3+ with extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `redis`, `tokenizer`, `xml`
- Node.js 20+ (local dev only; production assets are built in GitHub Actions)
- MySQL 8+
- Redis 7+
- Nginx (or Apache) + PHP-FPM
- Supervisor or systemd
- SSL certificate for public domain

---

## Environment Checklist

Copy `.env.example` to `.env` and configure:

| Variable | Production value |
|----------|------------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://your-domain.com` |
| `DB_*` | Production MySQL credentials |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_STORE` | `redis` |
| `SESSION_DRIVER` | `redis` |
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_*` | Production Reverb host/scheme/ports |
| `REVERB_ALLOWED_ORIGINS` | Comma-separated storefront domains |
| `HORIZON_ALLOWED_EMAILS` | Comma-separated admin emails |
| `CLOUDINARY_*` | Production Cloudinary account |
| `OPENAI_API_KEY` | Optional — AI script generation |
| `HEYGEN_API_KEY` | Optional — AI avatar videos |
| `DAILY_API_KEY` | **Required for Go Live** — Daily.co API key from [dashboard.daily.co](https://dashboard.daily.co) |
| `DAILY_ENABLED` | `true` (default) — set `false` only to disable browser go-live |

### Frontend assets (GitHub Actions)

Pushes to `main` / `master` run [`.github/workflows/build-assets.yml`](../.github/workflows/build-assets.yml). That workflow runs `npm run build`, then commits `public/build` and `public/embed` back to the branch. Forge should **not** run `npm` on deploy—pull the repo and use the committed assets.

### Laravel Forge deploy script (zero-downtime)

**Do not use** `$FORGE_PHP artisan optimize` in Forge. It runs `route:cache` internally and will fail with duplicate route names if the server is on an old release. Use explicit cache commands instead (see [`deploy/forge-deploy.sh`](../deploy/forge-deploy.sh)).

Replace the body of your Forge deploy script (between `$CREATE_RELEASE()` and `$ACTIVATE_RELEASE()`) with:

```bash
cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

$FORGE_PHP artisan route:clear
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan event:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan route:cache

$FORGE_PHP artisan storage:link || true
$FORGE_PHP artisan migrate --force
```

Keep Forge’s `$CREATE_RELEASE()`, `$ACTIVATE_RELEASE()`, and `$RESTART_QUEUES()` lines as-is.

**Verify the route-name fix is on the server** (SSH into the site):

```bash
grep "live-shows.page" $FORGE_RELEASE_DIRECTORY/routes/web.php
grep "name('admin." $FORGE_RELEASE_DIRECTORY/routes/api.php
```

Both commands should print a match. If you still see `live-shows.index` in `routes/web.php`, Forge is deploying an old commit — deploy branch `main` after commit `ded360b` or newer.

### Laravel Forge deploy script (simple / non-zero-downtime)

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

$FORGE_PHP artisan route:clear
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan event:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan storage:link || true
$FORGE_PHP artisan horizon:terminate

( flock -w 10 9 || exit 1
  echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
```

Ensure the site deploys from `main` / `master` **after** the `build-assets` workflow finishes (two commits per deploy: your code push, then the bot asset commit). Enable **Quick Deploy** only if you are fine waiting for CI; otherwise deploy manually or trigger Forge after Actions completes.

If branch protection blocks `github-actions[bot]` from pushing, allow the bot in repository rules or use a deploy key / PAT with write access.

Local-only asset rebuild:

```bash
npm ci
npm run build
```

Deploy application:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
```

---

## Nginx (app + Reverb proxy)

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/ai-video-ecommerce/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

# Optional: terminate TLS and proxy Reverb websockets
server {
    listen 443 ssl http2;
    server_name ws.your-domain.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 60s;
    }
}
```

Set in `.env`:

```env
REVERB_HOST=ws.your-domain.com
REVERB_PORT=443
REVERB_SCHEME=https
VITE_REVERB_HOST=ws.your-domain.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

---

## Supervisor — Laravel Horizon

Create `/etc/supervisor/conf.d/ai-video-horizon.conf`:

```ini
[program:ai-video-horizon]
process_name=%(program_name)s
command=php /var/www/ai-video-ecommerce/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ai-video-horizon.log
stopwaitsecs=3600
```

Reload Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ai-video-horizon
```

After each deploy:

```bash
php artisan horizon:terminate
```

Horizon dashboard: `https://your-domain.com/horizon` (restricted by `HORIZON_ALLOWED_EMAILS`).

---

## Supervisor — Laravel Reverb

Create `/etc/supervisor/conf.d/ai-video-reverb.conf`:

```ini
[program:ai-video-reverb]
process_name=%(program_name)s
command=php /var/www/ai-video-ecommerce/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/ai-video-reverb.log
```

Reload and start:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ai-video-reverb
```

---

## systemd alternatives

### Horizon

`/etc/systemd/system/ai-video-horizon.service`

```ini
[Unit]
Description=AI Video Horizon
After=network.target redis.service mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/ai-video-ecommerce/artisan horizon
WorkingDirectory=/var/www/ai-video-ecommerce

[Install]
WantedBy=multi-user.target
```

### Reverb

`/etc/systemd/system/ai-video-reverb.service`

```ini
[Unit]
Description=AI Video Reverb
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /var/www/ai-video-ecommerce/artisan reverb:start --host=0.0.0.0 --port=8080
WorkingDirectory=/var/www/ai-video-ecommerce

[Install]
WantedBy=multi-user.target
```

Enable services:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ai-video-horizon ai-video-reverb
```

---

## Scheduler (cron)

Add to crontab for `www-data`:

```cron
* * * * * cd /var/www/ai-video-ecommerce && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled tasks include Horizon snapshots and live show auto-transitions.

---

## Queue topology

Named queues (see `config/queue.php`):

| Queue | Purpose |
|-------|---------|
| `critical` | Time-sensitive jobs |
| `realtime` | Engagement broadcasts |
| `media` | Cloudinary + AI avatar rendering |
| `integration` | Shopify/Woo sync |
| `analytics` | Event rollups |

Horizon should supervise all queues configured in `config/horizon.php`.

---

## Staging Checklist

Use this before promoting to production.

### Infrastructure
- [ ] MySQL reachable from app host
- [ ] Redis reachable; separate DB indexes for cache/queue if needed
- [ ] Horizon running and processing test jobs
- [ ] Reverb running; websocket connects from embed player
- [ ] Cron scheduler active
- [ ] SSL valid on app + websocket subdomain

### Application
- [ ] `php artisan migrate --force` succeeds
- [ ] Admin login + team auto-provision works
- [ ] Upload video → Cloudinary processing → publish
- [ ] Product tagging + embed feed shows tagged products
- [ ] Cart + checkout path tested (native + external)
- [ ] Shopify/Woo sync tested with staging credentials
- [ ] AI script generation returns output (OpenAI or template fallback)
- [ ] AI avatar job completes (HeyGen or mock provider)
- [ ] All embed types render: vertical feed, floating widget, carousel, product page
- [ ] Analytics events appear in dashboard within rollup window

### Security
- [ ] `APP_DEBUG=false`
- [ ] Horizon restricted to allowed emails
- [ ] `REVERB_ALLOWED_ORIGINS` set to known storefront domains
- [ ] Webhook secrets configured (Cloudinary, Shopify, Woo)
- [ ] Rate limits verified under load (`tests/perf/player-feed.k6.js`)

### Observability
- [ ] Application logs shipping to central store
- [ ] Horizon failed jobs monitored
- [ ] Cloudinary webhook receipts recorded
- [ ] Backup policy for MySQL

---

## Rollback Plan

1. Put app in maintenance mode: `php artisan down`
2. Restore previous release + database snapshot if schema changed
3. `php artisan horizon:terminate`
4. Restart Horizon + Reverb via Supervisor/systemd
5. `php artisan up`

---

## Load Testing

See [load-testing.md](./load-testing.md). Run before major launches:

```bash
npm run perf:player-feed
```

Target: p95 feed latency under 500ms at 100 concurrent viewers on staging hardware.
