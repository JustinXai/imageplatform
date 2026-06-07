# imageplatform — Deployment Audit

**Date:** 2026-06-06
**Status:** Infrastructure Setup Required

---

## 1. Project Type

- **Language:** PHP 8.1 (Native, no framework)
- **Database:** MySQL 8.0 (PDO)
- **Web Server:** Caddy 2 (currently serving sellerfixhub.com on port 3000)
- **PHP Process Manager:** PHP-FPM 8.1 (already installed and running)
- **Background Worker:** CLI PHP script (`worker/generate_worker.php`) — must run persistently
- **Package Manager:** None (pure PHP, no composer)

---

## 2. Entry Points

| Route | File | Notes |
|---|---|---|
| `/` | `public/index.php` | SPA-like front page, includes other PHP files by path |
| `/generate` | `public/generate.php` | Main image generation API |
| `/generate_async` | `public/generate_async.php` | Async queue-based generation |
| `/queue_generate` | `public/queue_generate.php` | Enqueue generation task |
| `/check_record` | `public/check_record.php` | Poll generation status |
| `/order` | `public/order.php` | Shop/order page |
| `/pay_notify` | `public/pay_notify.php` | Payment callback |
| `/gallery` | `public/gallery.php` | Public gallery |
| `/user/index` | `public/user/index.php` | User dashboard |
| `/login` | `public/login.php` | Auth |
| `/api/*` | Various | API routes |

---

## 3. URL Rewriting

Uses custom front-controller routing inside `public/index.php`.
URLs like `/user/index` → `public/user/index.php`.
Nginx-equivalent rewrite logic is handled by Caddy + PHP-FPM.

---

## 4. Build / Install Scripts

```bash
# No npm/node build step. Pure PHP.
# Database migrations run automatically on first access (auto-schema).
# Admin panel also has a Migrator UI.
```

---

## 5. PHP Version

- **Required:** PHP 8.1+
- **Installed:** PHP 8.1.2 (cli + fpm)
- **Extensions needed:** `pdo_mysql`, `curl`, `mbstring`, `xml`, `gd`, `bcmath`, `zip`

---

## 6. Required Environment Variables (via config.php)

| Key | Notes |
|---|---|
| `db.host` | MySQL host (e.g. `127.0.0.1`) |
| `db.port` | MySQL port (default `3306`) |
| `db.database` | Database name |
| `db.username` | Database user |
| `db.password` | Database password |
| `db.charset` | `utf8mb4` |
| `app.base_url` | Public URL (e.g. `https://yourdomain.com`) |
| `app.timezone` | `Asia/Shanghai` |
| `generation.timeout` | Default `300` seconds |
| `pay.pid` | Payment platform PID (彩虹易支付) |
| `pay.key` | Payment platform key |
| `pay.notify_url` | Payment callback URL |
| `pay.return_url` | Payment return URL |

**App settings (stored in DB, configured via admin panel):**
| Key | Notes |
|---|---|
| `image_base_url` | Default: `https://api.kbl6.cn` |
| `image_api_key` | API key for image AI |
| `image_model` | Default: `gpt-image-2` |
| `video_base_url` | Video API base URL |
| `video_api_key` | Video API key |
| `platform_name` | Display name |
| `balance_label` | e.g. `余额` or `Credits` |
| `image_storage_mode` | `file` (default) or `base64` |
| `ssl_verify` | `true` in production |

---

## 7. Database

- **Required:** MySQL 8.0
- **Tables:** `users`, `ai_models`, `generation_records`, `chat_records`, `chat_conversations`, `orders`, `shop_packages`, `api_tokens`, `app_settings`, `gallery`, `social_logins`, `invite_commissions`, `credit_codes`, `credit_redemptions`
- **Schema:** Auto-migrated by PHP code on first run
- **Status:** MySQL is NOT currently running — must be installed and initialized

---

## 8. Redis

- **Required:** No (session stored in PHP sessions, cache in DB)

---

## 9. Third-Party API Keys

| Provider | Purpose |
|---|---|
| `api.kbl6.cn` (or custom) | GPT-image / DALL-E image generation API |
| 彩虹易支付 | Payment gateway (Alipay/WeChat Pay) |
| SMTP mail server | Email sending (configurable via admin) |
| 极验 V4 | CAPTCHA (optional, via app settings) |

---

## 10. Payment System

- **Type:** 彩虹易支付 (EPAY / Rainbow Easy Pay)
- **Integration:** `src/pay.php` — MD5 signed notifications
- **Status:** Requires PID/Key from payment provider

---

## 11. Storage

- **Mode:** `file` (default) or `base64` (stored in DB)
- **File upload path:** `public/uploads/` (must be writable by PHP-FPM)
- **Generated images path:** `public/uploads/generations/`

---

## 12. Background Worker

`worker/generate_worker.php` — CLI PHP script that:
- Polls `generation_records` table for `queued` tasks
- Calls image/video generation APIs
- Updates record status to `succeeded` or `failed`
- Cleans stale `running` tasks

**Must run persistently** alongside PHP-FPM.

---

## 13. Recommended Deployment Architecture

```
[Internet]
     |
  [Caddy :80/:443]  ← HTTPS termination, serves sellerfixhub.com + imageplatform
     |
     +-- reverse_proxy 127.0.0.1:3000 (sellerfixhub/Next.js)
     |
     +-- php_fastcgi unix:/run/php/php-fpm.sock (imageplatform)
     |
  [PHP-FPM 8.1] ← serves imageplatform public/
     |
  [MySQL 8.0] ← database (needs setup)
     |
  [php generate_worker.php] ← background worker (PM2 managed)
```

---

## 14. Risk Points

1. **MySQL not installed** — blocks all functionality
2. **Database `aio222`/`aio222` credentials** in config.php — must be verified before use
3. **Worker not running** — generation tasks will be queued but never processed
4. **Caddy currently serves sellerfixhub** on port 80 — adding imageplatform must not break it
5. **API keys** for `api.kbl6.cn` and payment gateway need to be verified
6. **`public/uploads/` must be writable** by www-data user
7. **`.installed` marker** in `storage/` directory must be present for app to start

---

## 15. What Was Not Changed

- No page content modified
- No business logic touched
- No source files altered
- No Git commits made (read-only audit phase)

