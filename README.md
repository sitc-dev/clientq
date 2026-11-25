# ClientQ

ClientQ is a multi-tenant PHP/MySQL waitlist. Businesses register at `clientq.local` and receive an isolated site at `<business>.clientq.local`. The platform registry and every business use separate MySQL databases.

## Run with Docker

Add the development names you want to test to `/etc/hosts`:

```text
127.0.0.1 clientq.local demo.clientq.local
```

Then start Apache/PHP and MySQL:

```bash
mkdir -p logs
docker compose up --build
```

Open `http://clientq.local:8080`. After signup, add the chosen subdomain to `/etc/hosts` if it is not already there. For arbitrary signup-created names, use the wildcard DNS setup below.

The main domain includes home, pricing, signup, and ClientQ platform-administration areas. Tenant owners and employees share the tenant staff portal, with owner-only business management and billing. The MySQL container initializes the central `clientq` registry database. Each signup creates a separate database named with a `clientq_tenant_` prefix and initializes only that business's settings, staff, and clients tables.

CloudBeaver provides browser-based DBeaver access at `http://localhost:8978`. Its **ClientQ MySQL** connection uses the `clientq` database user and exposes the central `clientq` database plus every `clientq_tenant_*` database. Local administrator credentials are stored in the git-ignored `cloudbeaver_credential.txt` file.

Owners can upload their logo from **Business**, maintain their own profile from **My Profile**, and create, edit, or deactivate accounts from **Users**. Uploaded logos are stored in the persistent `clientq_uploads` Docker volume.

When an owner selects a logo, ClientQ derives an accessible primary and accent color from the image and applies that theme throughout the business waitlist and portal. The platform marketing pages use a separate cool-gray theme with metallic red and steel-blue accents and a directional fading grid, configured in `src/php/marketing-theme.php`. Tenant branding and the tenant examples shown in product previews are unchanged by this marketing theme.

## Application logs

Application errors, failed logins, and rejected signup tokens are written to `logs/clientq-YYYY-MM-DD.log` on your local filesystem. Docker bind-mounts `./logs` at `/var/log/clientq` in the app container; files remain available when the container stops or is recreated. Create `logs/` as your normal user before starting Docker so its host group is retained. Container startup grants Apache write access while preserving that group's local access.

Every new application log entry uses `[YYYY-MM-DD HH:MM:SS] [ERROR|INFO|WARNING] [source/file.php] message`. Failures default to `ERROR`; completed tenant/owner lifecycle actions use `INFO`; failed logins, rejected signup tokens, and nonfatal cleanup failures use `WARNING`. Use `clientqLog($message, level: 'INFO')` or `level: 'WARNING'` for non-error events. Embedded line breaks are escaped so each entry remains one labeled line. Existing historical entries are preserved unchanged.

Read the current log without entering the container:

```bash
tail -f "logs/clientq-$(date +%F).log"
```

After changing this configuration, rebuild and recreate the app with `docker compose up -d --build app`. Existing logs in the previous `clientq_logs` named volume are not moved or deleted. Without Docker, the logger also defaults to the repository's `logs/` directory; `CLIENTQ_LOG_DIR` can override that path. Log files are git-ignored, excluded from Docker builds, and blocked from HTTP access.

## Requirements

- PHP 8.0+ with PDO MySQL
- MySQL 8+
- Apache with `mod_rewrite`

## Configure

Set the variables shown in `.env.example` in Apache/PHP's environment. Do not serve `.env` files from the document root.

For the GoDaddy MySQL server, set `CLIENTQ_DB_HOST=83.189.153.160.host.secureserver.net`, `CLIENTQ_DB_PORT=3306`, `CLIENTQ_DB_NAME=clientq_db`, and `CLIENTQ_DB_USER=client_db`. Set `CLIENTQ_DB_PASS` to the database user's password through the hosting environment or a protected `.env` file; do not commit it. Port 2083 is cPanel's HTTPS port, not the MySQL port. The database user must be assigned to `clientq_db`, and the hosting account must allow connections from the app server.

Create the central platform database tables:

```bash
php src/php/setup.php
```

The database user needs access to the central registry database plus permission to create, drop, and use databases matching `clientq_tenant_%`. The Docker setup grants those development permissions automatically. Shared hosting may restrict database creation or require account-prefixed database names; in that case, tenant signup provisioning needs corresponding hosting permissions and naming support.

Database provisioning is limited by `CLIENTQ_SIGNUPS_PER_HOUR` per source IP and `CLIENTQ_DAILY_SIGNUP_LIMIT` globally.

## PayPal signup subscriptions

Signup offers PayPal or credit/debit cards through Stripe Billing, and requires a server-verified, completed first payment before provisioning. Essentials is $39 USD/month for waitlist only, with optional appointment scheduling for an additional $5/month ($44/month total). Select is $64/month and Premier $99/month, both including appointments. Internal database plan keys remain `starter`, `growth`, and `pro`, respectively; PayPal configuration uses the displayed names below. Approval or an `ACTIVE` subscription alone never grants an account. PayPal handles subsequent monthly charges until the buyer cancels in PayPal; the tenant billing page links to those automatic-payment settings. Stripe invoice payments remain separate.

Apply `database/platform-paypal-migration.sql` to the central database before deploying to existing installations. New installations receive the table from the canonical schema.

Create a PayPal Sandbox REST application and four active subscription plans in that same merchant account: Essentials $39, Essentials with Appointments $44, Select $64, and Premier $99. Each plan must have exactly one regular cycle, frequency `MONTH` / `1`, unlimited cycles (`total_cycles: 0`), the corresponding fixed USD price, no trial, no setup fee, and no tax surcharge. Essentials with Appointments bills the base plan and add-on together as one $44 subscription, not a second subscription. Configure the values from `.env.example` without committing credentials:

- `CLIENTQ_PAYPAL_ENVIRONMENT=sandbox` (default; set `live` to select the production configuration)
- Sandbox: `CLIENTQ_PAYPAL_SANDBOX_CLIENT_ID`, `CLIENTQ_PAYPAL_SANDBOX_CLIENT_SECRET`, `CLIENTQ_PAYPAL_SANDBOX_PLAN_ESSENTIALS`, `CLIENTQ_PAYPAL_SANDBOX_PLAN_SELECT`, `CLIENTQ_PAYPAL_SANDBOX_PLAN_PREMIER`
- Live: `CLIENTQ_PAYPAL_LIVE_CLIENT_ID`, `CLIENTQ_PAYPAL_LIVE_CLIENT_SECRET`, `CLIENTQ_PAYPAL_LIVE_PLAN_ESSENTIALS`, `CLIENTQ_PAYPAL_LIVE_PLAN_SELECT`, `CLIENTQ_PAYPAL_LIVE_PLAN_PREMIER`
- `CLIENTQ_PAYPAL_SANDBOX_PRODUCT_ID` and `CLIENTQ_PAYPAL_LIVE_PRODUCT_ID` retain the catalog references; checkout uses each plan ID.
- `CLIENTQ_PAYPAL_SANDBOX_PLAN_ESSENTIALS_APPOINTMENTS` and `CLIENTQ_PAYPAL_LIVE_PLAN_ESSENTIALS_APPOINTMENTS` identify the $44 Essentials bundle. An unavailable bundle plan never falls back to the $39 plan.

The selected environment controls API authentication, plan selection, browser SDK client ID, and PayPal hosts together. Missing settings never fall back to the other environment or the former unprefixed variables. Existing installations must move their old unprefixed credentials and plan IDs into the corresponding environment-specific settings. Docker Compose passes both sets from `.env`; PHP reads environment variables rather than loading `.env` directly. After switching environments, recreate the app container and start a fresh signup session. An in-progress signup cannot be paid or provisioned using a different environment.

Rebuild the app after configuration. Use a separate Sandbox buyer account to approve a test subscription. The review page shows the monthly total and an embedded PayPal subscription button using the Web SDK v6. If the SDK cannot load or the browser is ineligible, an explicit message and hosted PayPal checkout button remain available. Existing checkout attempts can also resume through hosted checkout. Popup approval or a return to `/signup-confirmation` triggers server-side subscription, signup reference, plan, amount, currency, and completed transaction validation. Production must use HTTPS. Without credentials or plan IDs, signup shows a disabled PayPal interface and an explicit unavailable message, and cannot create accounts.

Payment attempts are idempotent, and paid-but-unprovisioned signups retain their details and subscription association centrally so the same browser session can retry without paying again. Do not start a new subscription after a payment/provisioning error. If the session is lost, contact platform support to reconcile the saved `paypal_signup_subscriptions` record; no automatic refunds or recovery-email workflow is implemented. Staged logos referenced by unfinished PayPal signups are preserved. Platform support must cancel/refund abandoned or failed paid signups in PayPal as appropriate, and remove unused pending records/logos under the deployment's retention policy.

This integration does not automatically suspend tenants for later payment failures or synchronize renewal transactions into Stripe invoices. PayPal remains authoritative for ongoing subscription status. Cancel subscriptions in PayPal before deleting tenants; database deletion alone does not stop recurring charges.

## Stripe Billing card signup

The review page also offers **Pay with credit/debit card**, using Stripe-hosted Checkout without a PayPal account. This creates one monthly subscription at the same $39/$44/$64/$99 USD prices. The $44 Essentials selection includes the appointment add-on in a single subscription, not a second charge. Checkout creates its recurring price from trusted server-side amounts, so no Stripe product or price IDs need to be configured.

Apply `database/platform-stripe-billing-migration.sql` to the central database before deploying to an existing installation. Configure `CLIENTQ_STRIPE_SECRET_KEY` with an `sk_test_...` key for tests or an `sk_live_...` key for real card payments. Stripe mode is independent of the selected PayPal environment, and in-progress signups cannot switch Stripe environments. Never commit secrets. Missing Stripe credentials disable only the card option, not configured PayPal checkout.

Configure `CLIENTQ_STRIPE_WEBHOOK_SECRET` and send `checkout.session.completed` and `checkout.session.expired` to the existing platform `/api/stripe-webhook` endpoint. Subscription events are kept separate from business-invoice events. Signed subscription-completion events are verified with a server-side Stripe lookup before recording payment. The buyer's return to signup confirmation verifies payment again and provisions the waitlist page and owner/staff portal. A return URL, a client-supplied session ID, or an active subscription alone cannot create a tenant: the associated Checkout session, exact monthly terms, paid first invoice, and successful unrefunded card payment must match.

Enable Stripe's customer portal separately for test and live modes in the Stripe Dashboard. Enable payment-method updates and subscription cancellation; leave plan changes disabled because ClientQ does not implement subscription upgrade/proration or entitlement synchronization. Tenant owners use **Manage card or cancel subscription** on `/admin/billing`; the portal is scoped to the tenant's saved Stripe customer, never a browser-supplied customer ID.

Checkout retries reuse the same session and idempotency key. Once either provider's checkout starts, another provider cannot be selected, preventing duplicate subscriptions. Paid-but-unprovisioned details and staged logos are retained in `stripe_signup_subscriptions` for retry in the same browser session. Lost sessions, expired card Checkout sessions, and abandoned paid signups need platform support reconciliation; do not start another payment. Renewal billing is managed by Stripe, but renewal failures/cancellation do not yet automatically suspend tenants. Cancel the subscription with the provider before deleting a tenant.

## Central accounts and invoices

Owners and employees sign in at their tenant's `/admin/login`. Owners have additional business-management permissions and an **Invoices & Payments** page at `/admin/billing`; employees cannot view invoices or start payments. The separate `/account` portal is retired. Central business-owner records are retained for ClientQ administration, not as a separate tenant login.

Create the first ClientQ platform administrator:

```bash
docker compose exec app php src/php/create-platform-owner.php "Platform Owner" owner@example.com "a-strong-password"
```

Only the ClientQ platform owner signs in at `/platform-admin/login` to view all ClientQ owner profiles and issue invoices. Tenant-owner and employee credentials do not grant platform administration access.

To link a central owner record for businesses created before central accounts were added (this does not create staff-portal credentials or grant platform administration):

```bash
docker compose exec app php src/php/create-business-owner.php business-slug "Owner Name" owner@example.com "a-strong-password"
```

Set `CLIENTQ_STRIPE_SECRET_KEY` and `CLIENTQ_STRIPE_WEBHOOK_SECRET`. Configure Stripe to send Checkout events to:

```text
https://clientq.example/api/stripe-webhook
```

The integration handles `checkout.session.completed` and `checkout.session.expired`. Invoice payment status changes only after a signed Stripe webhook or a server-side Stripe session lookup verifies the invoice metadata, amount, and currency. Checkout returns owners to their tenant's billing page; a success URL alone never marks an invoice paid.

For an existing database created by the old single-business version, edit the database and business values in `database/migrate-single-business.sql`, back up the database, then run that migration instead.

Existing installations also need:

- `database/platform-marketing-migration.sql` on the central platform database
- `database/platform-rate-limit-migration.sql` on the central platform database
- `database/platform-billing-migration.sql` on the central platform database
- `database/tenant-portal-migration.sql` on every existing tenant database
- `database/tenant-staff-profile-migration.sql` on every existing tenant database to add structured staff names and private contact details
- `database/tenant-employee-invitation-migration.sql` on every existing tenant database to support multiline experience and first-login password changes
- `database/tenant-email-profile-migration.sql` on every existing tenant database to add ZIP codes and use recorded email addresses for staff login. Resolve duplicate emails before applying it. Legacy employees without an email must have one added by their owner before they can log in.

New employee creation requires the `CLIENTQ_SMTP_HOST`, `CLIENTQ_SMTP_USER`, and `CLIENTQ_SMTP_PASS` settings. Owners enter the employee's email; the server generates and emails a temporary password. The new employee must set a different password before accessing staff pages or APIs. Mail failure rolls back account creation. Saving an existing employee does not send another invitation. The legacy `staff.username` column stores the login email; there is no separate username.

Owners and employees manage their own experience, private address/city/state/ZIP/phone, and public profile photo in **My Profile**. Photo uploads accept PNG/JPEG/WebP up to 2 MB and are bound to the authenticated user; owners cannot upload another employee's photo. Changing a login email invalidates that person's other sessions.

Owners edit exactly two base theme colors, **Primary** and **Accent**, in **Business Settings**. Saving colors does not require or replace a logo. Logo uploads can still suggest the two colors. Existing contrast normalization and automatically derived shades remain in effect.

The tenant waitlist's **QR Code** tab displays and downloads a framed signup QR code. Without a logo, the business name appears on one line above an unobstructed QR code. With a logo, only the logo appears in the QR's center. Both frames show **Powered by ClientQ** below the code. Frame text sits 6px from the dark code at the standard 320px preview width; this compact spacing reduces the usual vertical scan clearance. An unavailable logo falls back to the business-name frame with a visible notice. The high-resolution PNG preserves the same proportions and high error correction.

Stripe-subscribed owners also have **Subscription payment cards** in **My Profile**. They can save multiple cards using Stripe-hosted setup Checkout, choose the card for future monthly charges, and remove non-default cards. Adding a replacement does not change the subscription's default until the owner selects **Make default**. Only brand, last four digits, and expiration are shown; full card numbers and security codes never pass through ClientQ. Saved cards stay with the Stripe customer, not in a tenant database. Employees and PayPal-only owners do not have this card-management section.

## Onboarding phone-pool assignment

Every new tenant provisioned after a verified PayPal or Stripe payment is assigned one configured SMS phone group automatically. Assignment rotates through groups A-E in order, skips groups whose `phone_number` is `NULL`, and wraps around. Numbers are shared across tenants in the same group. The assignment and round-robin cursor commit with the tenant's central records; retries do not consume another turn, failed provisioning rolls back the assignment, and deleting a tenant does not rewind the cursor.

Existing platforms need `database/platform-phone-groups-migration.sql` if the phone-pool tables are not present, followed by `database/platform-phone-pool-assignment-migration.sql` on the **central database only**. The latter preserves existing memberships and initializes the cursor from the latest existing membership. Both tables and cursor are included in the canonical platform schema for new installations.

Configure pool numbers in `phone_groups` using E.164 format and ensure they are SMS-capable senders for the configured Twilio account. These migrations do not purchase numbers, insert test numbers, or copy local tenant data. If no number is configured, provisioning returns an explicit unavailable response, removes the incomplete tenant database, and retains the paid signup for retry without another payment.

## Tenant portal access levels

Owners can onboard and manage Owner, Manager, and Staff accounts under **Users**. Managers can onboard, edit, and deactivate Staff accounts only. Both Owners and Managers can complete or permanently delete any waiting client; Staff can complete their own or unassigned clients and delete their own clients only. Billing and Business Settings remain Owner-only. Access levels appear in My Profile and the portal header.

Invitations include the selected access level and a temporary password that must be changed at first login. Active Managers and additional Owners count toward the employee limit; one active Owner remains exempt. Role changes invalidate existing staff sessions. The last active Owner cannot be demoted or deactivated, and users cannot deactivate themselves.

Apply `database/tenant-staff-access-levels-migration.sql` to each existing tenant database before deployment. New installations include all three roles in `database/tenant-schema.sql`; no platform-administrator accounts are created.

## Daily waitlist hours and staff availability

Owners can set date-specific waitlist opening/closing times or close a day in **Business Settings**. Every staff member, including owners, can set their own date-specific hours or mark a day off in **My Profile**. Unconfigured dates default to **8:00 AM-4:30 PM Eastern time** for both settings. These controls are independent of appointment schedules.

The signup dropdown excludes staff who are off or have finished for the day. Staff whose shift starts later that day remain selectable with a start-time label and notice. New signups, including "First available staff," are rejected outside the owner's waitlist hours or when no staff remain available; the existing queue stays visible. Server-side checks use the same tenant-local schedules as the public page.

For existing deployments, apply `database/tenant-daily-waitlist-hours-migration.sql` separately to every existing tenant database before deploying these changes. The migration preserves legacy signed/unsigned staff IDs and existing data, and can be reapplied. New tenant databases include the tables in `database/tenant-schema.sql`.

## Shared waitlist

Clients may choose **First available staff** when joining the walk-in waitlist. These entries have no individual staff assignment; appointment bookings still require a specific staff member. Every authenticated owner and employee can see the tenant's full active waitlist in the portal, including staff assignments.

**Mark as completed** is available to Owners and Managers for any waiting client. Staff can complete clients assigned to themselves or to **First available staff**. Concurrent completion requests cannot complete the same waiting entry twice. Completion removes the client from active public and portal lists while retaining the record. Permanent **Remove** is available to Owners and Managers for any waiting client, and to Staff for their own assigned clients only. The displayed **Time waiting** is elapsed time since signup, not an estimated service delay.

Apply `database/tenant-any-staff-migration.sql` separately to every existing `clientq_tenant_*` database before deploying this feature. It makes `clients.staff_id` nullable while preserving its existing column type, foreign key, and assignments. New tenants receive this definition from `database/tenant-schema.sql`.

## Reservations

Apply `database/platform-appointment-addon-migration.sql` to the central database before deploying this feature to an existing installation. It adds `appointments_enabled` to businesses and PayPal signup records without deleting reservations. Essentials businesses default to waitlist only; Select and Premier include appointments regardless of that flag. New Essentials owners select the optional $5/month add-on at signup, and the entitlement is persisted only when the matching $44 payment has been verified and the tenant is provisioned. No existing-subscription upgrade or proration workflow is provided.

With appointment access enabled, the tenant waitlist's **Sign Up/Book Appointment** tab offers walk-in signup and advance reservations. Waitlist-only Essentials businesses show **Sign Up** without the appointment form; both public appointment APIs independently enforce the same entitlement. Clients select staff, a calendar date, party size, and an available time. Each party member takes one consecutive 45-minute slot; occupied or past times are excluded, and bookings are rechecked under a staff row lock before saving. Reservations remain separate from the live waitlist: clients sign in when they arrive. If appointment access is disabled, existing reservations remain available for staff to view and cancel, but schedule changes and new bookings are blocked.

Owners and employees configure their own weekly hours and booking window, view their own reservations, and cancel them from **Reservations** in the staff portal. No working days or bookable appointments are enabled until that person explicitly saves availability, including owners. The form suggests 8:30 AM–5 PM and up to 14 days ahead, using `America/New_York`, but leaves all working days unchecked. Existing explicitly saved schedules remain in effect. With those hours selected, the final start is 4:45 PM and finishes at 5:30 PM for one person. Changing hours preserves existing reservations. Employees with unfinished reservations cannot be deactivated until those reservations are cancelled or completed.

Apply `database/tenant-reservations-migration.sql` separately to every existing `clientq_tenant_*` database before serving the updated app. New tenants receive these tables from `database/tenant-schema.sql`.

## Local wildcard domains

Both the main domain and wildcard subdomains must resolve to the same Apache virtual host/document root.

Example Apache virtual host:

```apache
<VirtualHost *:80>
    ServerName clientq.local
    ServerAlias *.clientq.local
    DocumentRoot /path/to/clientq

    <Directory /path/to/clientq>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

For a quick single-tenant test, add explicit names to `/etc/hosts`:

```text
127.0.0.1 clientq.local demo.clientq.local
```

For arbitrary signup-created subdomains, configure a local wildcard DNS resolver such as dnsmasq:

```text
address=/clientq.local/127.0.0.1
```

Open `http://clientq.local`, create a business, and sign in through the generated tenant URL.
