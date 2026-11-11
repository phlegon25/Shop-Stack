# Security Architecture & Requirement Mapping

This document maps every mandatory security requirement to its concrete
implementation in this codebase. The governing principle is **ZERO TRUST**:
nothing the client sends — tenant IDs, roles, permissions, subscription
status, prices, totals — is ever trusted. Identity, tenancy and state are
re-derived server-side on every request.

---

## 1. Authentication

| Requirement | Implementation |
|---|---|
| No plaintext passwords | `User`/`SuperAdmin` models use Laravel's `hashed` cast; hashing happens on every assignment. Test: `AuthenticationTest::passwords_are_never_stored_in_plaintext`. |
| Modern adaptive hashing | **Argon2id** is the default driver (`config/hashing.php`, `HASH_DRIVER=argon2id`, memory 64 MiB / time 4 / threads 1). Bcrypt (12 rounds) kept as fallback. Rehash-on-login enabled. |
| Hashes never returned | `$hidden = ['password']` on both models; responses use `publicPayload()` allowlists. Test scans responses for `password` and the hash string. |
| Secure password reset | `PasswordResetService`: 64-char random tokens, stored **only as SHA-256 hashes**, 30-min TTL, **single-use** (`used_at` consumed inside the same transaction), previous tokens invalidated on new request, identical response for unknown emails (no enumeration). Tests: `PasswordResetTest`. |
| Session invalidation after password change | `changePassword` revokes all tokens except the current one; reset revokes ALL tokens. |
| Login throttling / brute-force | Two layers: route-level rate limiter + controller lockout via `RateLimiter` keyed on `sha1(email|ip)` — 5 failures → 15 min lock (tenant), 3 failures → 30 min (super admin). Locked/failed attempts audited. |
| Secure session handling | Sanctum bearer tokens with explicit per-token `expires_at` (tenant 12 h / remember 30 d / super admin 8 h). Logout deletes the token; tokens revocable individually or en masse. |
| Remember Me | `remember=true` → 30-day token vs 12-hour session token. Tested. |
| Super Admin stronger auth | Separate `super_admins` table & plane, stricter lockout (3/30 min), shorter sessions (8 h), no remember-me. |
| **Mandatory 2FA** | Accounts without TOTP receive only a restricted `2fa-setup` token which middleware blocks from all real endpoints (`two_factor_required`). Enrolled accounts must complete a single-use, 5-min challenge. RFC 6238 implementation in `TotpService` (±1 step, `hash_equals`). Secret stored **encrypted**. |
| No stale identity | `App\Support\BearerPrincipal` resolves identity **directly from the bearer token** of the current request (bypasses guard caching) and checks expiry; downstream code uses that identity. |

## 2. Authorization

- Middleware stack per tenant business route, in enforced order:
  `auth:sanctum → EnsureTenantContext → (binding) → subscription.active → permission`.
  Custom priority list in `bootstrap/app.php` guarantees **tenant context is set BEFORE route-model binding**.
- `EnsurePermission` checks DB role → config allowlist (`config/pos.php`). Ownership: cross-tenant records 404 by scope; financial records store `user_id` server-side.
- Privilege-escalation guards: `owner` role can never be granted/modified via API; owners cannot self-demote/disable; disabled users' tokens rejected; role/status changes revoke the target's sessions.

## 3. Multi-Tenant Security

- `TenantScope` global scope + `BelongsToTenant` trait on every tenant-owned model. `tenant_id` is stamped from `TenantContext` (derived from the token's user / impersonation session), **never from input** — proven by `test_client_supplied_tenant_id_is_never_trusted` which injects `tenant_id` in query, body AND URL.
- `GET /api/v1/products/123` for another tenant → 404 (also update/delete/sale-of-foreign-product covered).
- Super-admin access is a separate plane, separately audited (`actor_type=super_admin`).

## 4. SQL Injection

- Eloquent/Query Builder bindings everywhere; dynamic sort fields via `ListQuery::applySort` against `config('pos.sortable.*')` allowlists; LIKE wildcards escaped; IDs/filters/pagination validated (`FormRequest`s + clamps). `TenantIsolationTest`/`InputValidationSecurityTest` include live injection payloads (`'; DROP TABLE …`) asserting 200-OK + tables intact.

## 5. XSS

- JSON-only API (no server-rendered HTML) with `Content-Type: application/json`, `X-Content-Type-Options: nosniff`, and a restrictive CSP (`default-src 'none'; frame-ancestors 'none'`). `ForceJsonResponse` pins `Accept: application/json`. User content is stored/returned as data only.

## 6. CSRF

- Authentication is **bearer-token only** — browsers never attach credentials automatically, eliminating the CSRF surface by design (cookies are not used for auth). This is a documented architectural decision rather than a hidden-token flow.

## 7/8. Clickjacking & Security Headers

`SecurityHeaders` middleware on all API responses: `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy` (camera/mic/geo/payment off), `Cross-Origin-Resource-Policy: same-origin`, **HSTS** (sent only on TLS requests), `X-Powered-By` removed. Asserted in `InputValidationSecurityTest`.

## 9. API Security

- Versioned prefix `/api/v1`, uniform safe errors (`bootstrap/app.php` custom renderer: 500s return a generic message; stack traces/SQL/file paths never leave the server even when unauthenticated, and `debug` extras only exist with `APP_DEBUG=true`).
- Every protected endpoint: authenticate → tenant context → subscription gate → permission. Path params are resolved through the tenant scope; query params validated/allowlisted.
- Safe enumeration: missing models = 404, not 500.

## 10. Rate Limiting

Named limiters (`AppServiceProvider`): `auth.register` 10/h/IP · `auth.login` 10/min/IP (+controller lockout) · `auth.reset` 5/min/IP · `sa.auth` 8/min/IP (+stricter lockout) · `tenant.api` 120/min/user · `sa.api` 60/min/admin · `payments.submit` 10/min · `impersonate` 10/hour · `uploads` 30/min. 429 responses include `code: rate_limited`.

## 11. Input Validation

Every mutating endpoint uses a dedicated `FormRequest` with type/length/format/range allowlists and tenant-scoped `Rule::exists` checks (e.g., category must belong to THIS tenant). Controllers pass `$request->validated()` only.

## 12. Mass-Assignment Protection

- Strict `$fillable` allowlists; controllers consume `validated()` whitelists; service layer uses `forceFill` explicitly for protected fields.
- Protected-and-never-client-set: `tenant_id`, `user_id`, `role`, `status`, `stock_quantity`, payment `status/amount/reviewed_*`, `subscription_id`, suspension fields, `is_super_admin` (doesn't exist on tenant users at all).
- `Model::preventSilentlyDiscardingAttributes` enabled under `APP_DEBUG=true` so attempts throw loudly in dev/test. Proven live: posting `tenant_id/stock_quantity/role/is_super_admin` leaves records untouched.

## 13. File Upload Security

- MIME validated server-side (`mimetypes` + `image` rule), size caps (2 MB products / 4 MB proofs), dimension caps, extension re-derived from real MIME, **random 40-char filenames**, original filename discarded.
- Product images: public disk, streamed through a **tenant-scoped** endpoint. Payment proofs: **private disk**, streamed only through the super-admin endpoint, and **every view is audit-logged**.
- Executables impossible: only jpeg/png/webp pass validation; `nosniff` on streamed responses.

## 14. Payment Security

- No sensitive payment credentials stored — only plan, method, sender number/name, reference, proof image.
- Payment `reference` is globally unique (replay/duplicate rejected cross-tenant).
- Amount is read from the server-side plan; status forced `pending`; review fields set only by `SubscriptionService::approvePayment/rejectPayment` under row locks in one transaction. Double-review blocked (422).
- Tenant-plane PATCH/PUT on payments **does not exist** (405/404). All approvals/rejections audited with actor, tenant, reference, reason.

## 15. Platform Payment Account Security

`PlatformSetting` row is reachable for mutation only via `superadmin.perm:settings.manage`. Tenants see a sanitized projection (`publicPaymentInfo`, no `updated_by/at`). Changes record `updated_by` and create an audit entry listing **which fields changed** (values deliberately not logged).

## 16. Subscription Security

`EnsureSubscriptionActive` middleware (server-side only): suspended tenants → everything 403 `tenant_suspended`; expired tenants → mutations 403 `subscription_expired`, reads + billing remain open for renewal. Client hints (`subscription_status`, etc.) are ignored — tested. Expiry swept lazily per request + status transitions stored.

## 17. Super Admin Security

- Separate plane (`/api/v1/super-admin/*`), separate model/guard/token ability, mandatory TOTP, stricter lockout & limits, per-endpoint permission checks, full audit trail.
- Dangerous actions gated: suspension requires `confirm: accepted` + reason; tenant creation emits trial subscription transactionally; admin deactivation revokes sessions immediately (and self-deactivation is blocked).

## 18. Tenant Impersonation Security

- Requires `impersonate` permission + explicit `reason` (min 10 chars) + hourly rate limit.
- Session = **short-lived token (60 min)** — tenant credentials are never read, exposed or copied (asserted: no `password` string in response).
- Session record with start/expire/end; expiry enforced twice (token + record); early-stop endpoints on both planes.
- Responses carry `X-Impersonation-Active` header + `/me.impersonation` payload + a `banner` object at start so clients render a persistent banner.
- Actions during impersonation are stamped: `created_by_impersonation` on sales, `impersonation_session_id` on stock movements and audit rows. Start/end audited with reason.

## 19. Audit Logging

Append-only `audits` (model events hard-block update/delete with `LogicException`). Logged actions include: login/failed-login/lockout, logout(-all), password change/reset request+completion+failures, user create/update (role/status), tenant create/suspend/restore (+registration), subscription trial/activate/adjust, payment submit/approve/reject/proof-view, product create/update/delete, stock adjustments, sale create/void/return, purchase receive/cancel, platform setting changes, all super-admin auth events, impersonation start/end, plus `authz.denied` for suspension/subscription blocks. Context payloads pass through a **secret deny-list**, so passwords/tokens/OTPs can never be logged (tested).

## 20–22. Data Protection, Secrets, Error Handling

- TLS-only in production + HSTS on secure requests; bearer tokens over HTTP(S) headers only; **no auth data in localStorage-requiring flows** (bearer tokens suit `Authorization` header clients).
- All secrets from env; `.env` git-ignored; `.env.example` documents every variable without values; seeded dev credentials flagged local-only.
- Production error contract: generic 500 message server-side, no traces/SQL/paths/debug info; details go to the server log channel only.

## 23. Database Security

- Foreign keys (cascade/restrict chosen per relationship), unique constraints (`tenant_id+sku`, `tenant_id+receipt_no`, payment `reference`), composite indexes on hot tenant paths.
- Parameterized queries throughout; least-privilege DB user documented for production.
- **Transactions**: sale create/void/return, purchase receive/cancel, stock adjustments, payment review, tenant registration — all wrap related writes + stock decrements with `lockForUpdate` row locks. Negative stock throws inside the transaction (row-level check-then-write).

## 24. Financial Data Integrity

- Completed sales are immutable (no update endpoint — PUT/PATCH/DELETE → 405).
- Corrections = void (status flip + reversing stock + audit) or returns (append-only records guarding sold-vs-returned quantities).
- Sale items snapshot product name/price so history never rewrites itself.
- Stock is a ledger: every mutation writes an append-only `stock_movements` row (type, delta, resulting balance, reference, actor, impersonation stamp).
- Products with financial history cannot be hard-deleted (deactivated instead) — `product_id` foreign keys `restrictOnDelete` back this up.

## 25. Security Testing (automated)

`php artisan test` → **89 tests / 328 assertions**, including the mandated cases:

- ✅ **TENANT A CANNOT ACCESS TENANT B** (`TenantIsolationTest` — read/update/delete/sell/pay/category/URL+body manipulation)
- ✅ **TENANT USER CANNOT ACCESS SUPER ADMIN** (`SuperAdminAuthTest`, `AuthenticationTest::wrong_account_type_tokens…`)
- ✅ **CASHIER CANNOT PERFORM ADMINISTRATIVE ACTIONS** (`AuthorizationTest`)
- ✅ **EXPIRED TENANT CANNOT BYPASS SUBSCRIPTION RESTRICTIONS** (`SubscriptionEnforcementTest`)
- ✅ **UNAUTHORIZED USER CANNOT APPROVE PAYMENTS** (`PaymentSecurityTest`)
- Plus: lockouts, remember-me expiry, reset tokens (hash-only/single-use/expiry/session revocation), impersonation lifecycle, audit immutability & secret deny-list, injection/XSS/header checks, 2FA challenge single-use, financial reversals.

## OWASP Top-10 orientation

| Risk | Primary mitigations |
|---|---|
| A01 Broken Access Control | Tenant scope + pre-binding context + permission middleware + IDOR tests |
| A02 Cryptographic Failures | Argon2id, encrypted TOTP secrets, hashed reset tokens, TLS/HSTS |
| A03 Injection | ORM bindings, sort/filter allowlists, escaped LIKE, FormRequests |
| A04 Insecure Design | Zero-trust tenant derivation, separate admin plane, transaction integrity |
| A05 Security Misconfiguration | Security headers, safe error contract, debug-gated detail, env secrets |
| A07 AuthN Failures | Lockouts, throttles, 2FA, token expiry + revocation, reset hardening |
| A08 Data Integrity Failures | Immutable financial docs, reversal records, append-only ledgers/audits |
| A09 Logging Failures | Tamper-resistant audit trail for every sensitive action |
| A10 SSRF | No outbound URL fetching from user input in this phase |

## Remaining hardening before go-live (explicit non-claims)

1. Password-reset emails use the log mailer locally — wire real SMTP + queued notifications.
2. Login throttle state uses the app cache — use Redis in multi-node deployments so lockouts are global.
3. DB-level immutability for `audits`/`stock_movements` is enforced at app level; add DB user privilege restrictions (REVOKE UPDATE/DELETE) for defense in depth.
4. Automated MoMo capture (Paystack/Hubtel webhooks) is a future phase; webhook signature verification must be added with it.
