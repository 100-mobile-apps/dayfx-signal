# Deploying the DayFX update to the Vultr server

Live host: **https://dayfxsignal.100apkgoals.com** (backend served from the site root, API at `/api`).
This update adds the **web app** (`/app/`), the **admin panel** (`/admin/`), **Premium via Paystack** and the **affiliate system**.
Everything below keeps the existing users and signals.

## 1. Update the files
Copy the contents of `backend/` over the current document root. **Keep the server's existing `config.php`.**
New files: `app/`, `admin/`, `lib/accounts.php`, `r.php`, `index.php`, `install.php`, `schema_upgrade_web.sql`.
`admin.php` now just redirects to `admin/`.

## 2. Add the new keys to the existing `config.php`
```php
'base_url' => 'https://dayfxsignal.100apkgoals.com',   // no trailing slash
'admin_emails' => ['OWNER_EMAIL'],                      // ask the owner; they sign up with it to get /admin/
'paystack_secret_key' => '',                            // leave empty until the owner adds it; Premium stays hidden
```
`admin_user` / `admin_pass_hash` from the old config are no longer used.

## 3. Upgrade the database (once)
Either open `https://dayfxsignal.100apkgoals.com/install.php` (it detects the old tables, runs `schema_upgrade_web.sql`,
checks the setup) **and then delete `install.php`**, or run:
```bash
mysql DBNAME < schema_upgrade_web.sql
```

## 4. URL rewrites
Apache uses `backend/.htaccess` (needs `AllowOverride All`). On **nginx**, the equivalent is:
```nginx
location ~ ^/(lib|tools)/ { deny all; }
location ~ ^/(config\.php|config\.sample\.php|schema\.sql|schema_upgrade_web\.sql|cron\.php|backtest\.php|README\.md)$ { deny all; }
location ~ ^/r/([A-Za-z0-9]{4,16})/?$ { rewrite ^/r/([A-Za-z0-9]+)/?$ /r.php?c=$1 last; }
location /api/ { try_files $uri $uri/ /api/index.php?$query_string; }   # same API rule as before
location /admin/ { index index.php; }
location /app/ { index index.html; }
```
(Keep whatever the existing API rule is if it already works.)

## 5. Cron
Unchanged: `cron.php` every 15 minutes (minutes 1,16,31,46). Manual signals posted in the admin panel are settled by it too.

## 6. Smoke test
- `/api/config` returns JSON with `"premium_enabled": false`
- `/app/` loads the sign-in page; sign up, then `/admin/` works for the admin email
- `/r/ANYCODE` redirects to `/app/?ref=ANYCODE`
- `/lib/accounts.php` and `/config.php` return 403/404

## 7. Paystack (when the owner is ready)
Put the secret key in `config.php`, set the webhook in Paystack to
`https://dayfxsignal.100apkgoals.com/api/paystack/webhook`, then switch **Premium on** in `/admin/` › Settings.
