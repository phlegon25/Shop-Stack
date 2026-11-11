# ShopStack — Multi-tenant POS / Business SaaS

A Laravel API + vanilla-JS web app (no build step, no Node required on the
client) for a subscription-based, multi-tenant point-of-sale platform.
Tenants run products, inventory, sales and purchases; the platform operator
bills subscriptions via manual **MoMo / bank payments reviewed by a Super
Admin**. Security is architectural — see **[SECURITY.md](SECURITY.md)** for
the full requirement-to-control mapping.

## Stack

- Laravel 13 · PHP 8.3+ (built on 8.4)
- Sanctum bearer tokens (two strictly separated planes)
- SQLite (dev/test) · MySQL/PostgreSQL (production)
- Argon2id password hashing · TOTP (RFC 6238) 2FA for Super Admin
- Front ends: 3 static surfaces served from `public/` (vanilla JS + one CSS file)
  - `/` → `landing.html` — marketing site + plan catalogue + sign-up
  - `/app` → tenant workspace SPA (POS, inventory, money, reports, settings)
  - `/app/admin.html` → Super Admin platform console

## Quick start (local)

**Requirements:** PHP 8.3+ with `pdo_sqlite` (or MySQL 8+), Composer, `php-zip`/`php-xml`/`php-mbstring`.

```bash
# 1. Install PHP dependencies (no npm / no build needed — the frontend is static)
composer install

# 2. Configure environment
cp .env.example .env
php artisan key:generate

# 3a. Easiest: SQLite (zero additional setup)
#     in .env set:
#       DB_CONNECTION=sqlite
#       DB_DATABASE=/absolute/path/to/database/database.sqlite
touch database/database.sqlite

# 3b. Or MySQL: create the database + user, then point DB_* in .env at it
#     (DB_CONNECTION=mysql, DB_DATABASE=shopstack, ...)

# 4. Schema + demo data (demo tenants, plans, platform settings, super admin)
php artisan migrate:fresh --seed

# 5. Run it
php artisan serve --host=0.0.0.0 --port=8000
```

Then open **http://localhost:8000** and pick a surface:

| Surface | URL | Sign in with |
|---|---|---|
| Landing / sign-up | `http://localhost:8000/` | (no sign-in) |
| Store workspace | `http://localhost:8000/app` | `jerry@demo.test` / `Tenant#Pass2026` |
| Platform console | `http://localhost:8000/app/admin.html` | `admin@platform.local` / `ChangeMe-Platform!2026` |

> 2FA note: the platform console forces TOTP enrollment on first login. In
> local dev you can grab the current code via
> `php artisan tinker --execute='echo app(App\Services\TotpService::class)->currentCode(App\Models\SuperAdmin::first()->two_factor_secret);'`

### Demo credentials (LOCAL ONLY — created by the seeders)

| Plane | Account | Password |
|---|---|---|
| Jerry's Provision Store (owner) | `jerry@demo.test` | `Tenant#Pass2026` |
| Accra Retail (owner) | `adjoa@demo.test` | `Tenant#Pass2026` |
| Accra Retail (cashier) | `kofi@demo.test` | `Tenant#Pass2026` |
| Kumasi Mart (owner) | `yaw@demo.test` | `Tenant#Pass2026` |
| Super Admin | `admin@platform.local` | `ChangeMe-Platform!2026` (2FA enrollment forced at first login) |

### Smoke test

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"jerry@demo.test","password":"Tenant#Pass2026"}'
```

### Run the test suite

```bash
php artisan test          # 113 tests / ~500 assertions — should be all green
```

## API layout (all under `/api/v1`)

**Tenant plane** (tenant tokens only)

- `POST /auth/register` · `POST /auth/login` (`remember` supported) · `POST /auth/forgot-password` · `POST /auth/reset-password`
- `GET /me` · `POST /auth/logout` · `POST /auth/logout-all` · `POST /auth/change-password`
- `GET/POST /categories`, `PUT/DELETE /categories/{id}`
- `GET/POST /products`, `GET/PUT/DELETE /products/{id}`, `POST /products/{id}/image`, `GET /products/low-stock`
- `GET/POST /suppliers`, `PUT/DELETE /suppliers/{id}`
- `GET/POST /sales`, `GET /sales/{id}`, `POST /sales/{id}/void`, `POST /sales/{id}/returns`
- `GET/POST /purchases`, `GET /purchases/{id}`, `POST /purchases/{id}/cancel`
- `GET /stock/movements`, `POST /stock/adjustments`
- `GET /reports/sales-summary`, `GET /reports/top-products`
- `GET/POST /users`, `PUT /users/{id}` (owner only)
- Billing: `GET /billing/status` · `GET /billing/plans` (public) · `GET /billing/payments` · `POST /billing/payments` · `GET /billing/platform-payment-info` (read-only public projection)

**Super Admin plane** (separate endpoints, separate tokens, mandatory TOTP 2FA)

- `POST /super-admin/login` → 2FA setup-or-challenge flow
- `GET /super-admin/tenants` · `POST` · `GET /super-admin/tenants/{id}` · `POST .../suspend` (+`confirm`) · `POST .../restore` · `POST .../subscription`
- `GET /super-admin/payments` · `POST /super-admin/payments/{id}/review` (approve/reject) · `GET .../proof` (logged)
- `GET/PUT /super-admin/settings/payment` (MoMo/bank — audited)
- `POST /super-admin/tenants/{id}/impersonate` (`reason` required) · `POST /super-admin/impersonations/{id}/stop` · tenant-side `POST /impersonation/end`
- `GET /super-admin/audits` · `GET/POST /super-admin/admins` · `POST /super-admin/admins/{id}/deactivate`

## Authorization model

- Tenant roles: **owner** (all), **admin** (manage except staff/billing admin), **cashier** (sell only).
  Permissions are resolved from the DB role via `config/pos.php` — never from client input.
- Super Admin permissions are per-account (`super_admin_permissions` map), checked per endpoint.

## Testing

```bash
php artisan test        # 89 tests / 328 assertions
```

The suite proves, among others: **Tenant A cannot access Tenant B**,
**tenant tokens cannot reach Super Admin**, **cashiers cannot perform
administrative actions**, **expired/suspended tenants cannot bypass
restrictions**, **tenants cannot approve payments**, rate-limit lockouts,
password-reset single-use/expiry, immutability of sales and audit records.

## Production notes

- Serve only over **HTTPS** (HSTS activates automatically on secure requests).
- Set `APP_DEBUG=false`, `APP_ENV=production`; use MySQL/PostgreSQL with a least-privilege DB user.
- Secrets come from the environment only; never commit `.env`.
- Tokens are bearer tokens — no auth cookies, so the CSRF surface is eliminated by design.

## Roadmap (next phases)

- Frontend dashboard (tenant POS UI + super-admin console)
- E-mail notifications for payment decisions & suspension events
- CSV exports & deeper reports
- Automated MoMo provider integration (Paystack/Hubtel) as an alternative to manual review
