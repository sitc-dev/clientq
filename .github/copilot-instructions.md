# ClientQ repository instructions

## Build, test, and analysis

- Install dependencies with `composer install`.
- Start the complete local stack with `docker compose up --build`. The app is served at `http://clientq.local:8080`, MySQL is initialized from `database/platform-schema.sql`, and CloudBeaver is available at `http://localhost:8978`.
- Add `clientq.local` and any tenant host such as `demo.clientq.local` to `/etc/hosts`, or configure wildcard DNS. Both platform and tenant requests must reach the same Apache document root.
- Run static analysis with `composer analyze`. The current script scans `lib/` and `.` (including vendored code) and presently reports third-party/missing-optional-dependency errors; distinguish those from errors in first-party PHP.
- Run the configured test command with `composer test`. There is currently no `phpunit.xml` or first-party test directory, so the command prints PHPUnit usage until tests/configuration are added.
- Run one test explicitly with `vendor/bin/phpunit tests/SomeTest.php --filter testMethodName` (or pass the test file to `composer test -- tests/SomeTest.php`).
- Run all quality checks with `composer quality`.
- `composer build` runs analysis and tests, then executes `composer install --no-dev --optimize-autoloader`; it is production preparation and removes development packages from the local `vendor/` tree.
- There is no separate formatter or PHP syntax-lint script.

## Architecture

ClientQ is a framework-free, procedural PHP 8/MySQL application served by Apache. `.htaccess` rewrites extensionless URLs to individual `.php` entry points. Pages render PHP templates directly and generally keep Tailwind CDN configuration and browser JavaScript inline; JSON endpoints live under `api/`, `admin/api/`, and `account/api/`.

Routing and data isolation are host-based:

- `clientq.local` (and `www`) is the platform host. It serves marketing/signup pages, business-owner billing under `account/`, and ClientQ administration under `platform-admin/`.
- `<slug>.clientq.local` is a tenant host. Public waitlist pages are at the repository root and staff/owner pages are under `admin/`.
- `src/php/config.php` is the shared bootstrap. It resolves the request host, looks up the tenant in the central database, exposes platform/tenant authorization helpers, and creates PDO connections.
- `getDB()` connects to the central registry database (`businesses`, platform users, ownership, invoices, rate-limit records). `getTenantDB()` connects to the resolved business database (`staff`, singleton `business_settings`, and `clients`). Never put tenant operational rows in the platform database or cross-tenant data in a tenant database.
- Signup in `api/register-business.php` serializes provisioning with a MySQL named lock, creates a deterministic `clientq_tenant_<hash>` database, applies `database/tenant-schema.sql`, seeds the owner, then records the business and owner relationship centrally. Preserve its rollback/drop-database cleanup behavior.
- `database/platform-schema.sql` and `database/tenant-schema.sql` are canonical schemas for new installations. Existing deployments are upgraded by the targeted migration scripts in `database/`.
- Stripe integration is isolated in `src/php/stripe.php`, `account/api/create-checkout-session.php`, and `api/stripe-webhook.php`; payment state becomes authoritative only after signature validation and invoice/session amount, currency, and metadata checks.

## Repository conventions

- Begin first-party entry points with `require_once` for `src/php/config.php`; call `session_start()` before session-based authentication. Use `requirePlatformHost()`/`requirePlatformRole()` for central pages and `requireTenant()` plus `getSessionStaff()` for tenant pages.
- Pass `true` to host/auth helpers from JSON endpoints so failures remain JSON. APIs consistently validate the HTTP method and request body, return `{ "error": "..." }` with an appropriate 4xx/5xx status, and return `{ "success": true }` on success.
- Platform account mutations use `platformCsrfToken()` and `verifyPlatformCsrfToken()`. Tenant admin authorization is tied to both `staff_id` and `business_id` so a session cannot be reused on another subdomain.
- Use PDO prepared statements with emulated prepares disabled. Transactions and row locks are used for multi-step state changes and quota enforcement; retain those boundaries when modifying billing, provisioning, or employee limits.
- Log internal exception details with the `ClientQ ... failed:` prefix and send a generic response to users. Handle MySQL duplicate-key error `1062` as a conflict where existing endpoints do so.
- Generate cross-host links with `platformUrl()` and `tenantUrl()` so development ports and HTTPS are preserved. Use extensionless links because Apache performs the `.php` rewrite.
- Escape server-rendered output with `htmlspecialchars()` and JSON-encode values embedded into JavaScript. Browser-rendered API values use DOM text properties or the local `escapeHtml()` pattern.
- Tenant branding comes from the singleton `business_settings` row through `businessLogoUrl()` and `businessTheme()`. Keep the contrast normalization in `src/php/config.php` and the shared color names (`primary`, `accent`, `accentDark`, `soft`, `primarySoft`) consistent across tenant pages.
- When changing tables, update the appropriate canonical schema and add/update a migration for existing databases. A tenant migration must be applied separately to every `clientq_tenant_*` database.
- `lib/PHPMailer-6.9.3/` is vendored upstream code; application mail behavior belongs in `mail.php`, not in the library.
