# SFMTP — Smart Farm Management & Traceability Platform

A plain PHP + MySQL web app for running a farm: its structure, crops, livestock,
workers and tasks, stock, purchasing, money and sales. It also records traceability
from seed to customer, with public QR pages, and has portals for the farm's suppliers
and customers.

- **No framework, no Composer, no build step.** Copy the folder to any PHP host (XAMPP,
  cPanel shared hosting, a VPS) and it runs.
- **One database.** `database/sfmtp.sql` creates every table and loads the demo farms,
  people and history.

## Requirements

- PHP 8.2 or newer with the `pdo_mysql`, `openssl` and `mbstring` extensions (all standard in XAMPP).
- MySQL 8.0 or newer. MariaDB 10.6+ should also work but is not tested.
- Apache with `.htaccess` enabled (XAMPP and cPanel have this by default), or nginx (see below).

## Install

1. **Copy the folder** into your web root. For example:
   - XAMPP: `C:\xampp\htdocs\sfmtp`
   - cPanel: `public_html/sfmtp`
2. **Create the database** and import the dump. You can use phpMyAdmin (create `sfmtp` with
   collation `utf8mb4_unicode_ci`, then Import → `database/sfmtp.sql`) or the command line:

   ```sh
   mysql -u root -p -e "CREATE DATABASE sfmtp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -u root -p sfmtp < database/sfmtp.sql
   ```

   Optional but recommended: also import `database/sfmtp-triggers.sql`. It adds triggers that
   make the traceability history, ledger and audit log append-only inside MySQL itself. On
   hosts with binary logging, the importing user needs the `TRIGGER` privilege, or
   `log_bin_trust_function_creators = 1`.
3. **Configure.** Copy `config.sample.php` to `config.php`, then:
   - enter the database details;
   - set `app_url` to the site's address (QR codes link to it);
   - set `secret_key` to a long random string: `php -r "echo bin2hex(random_bytes(32));"`.

   `config.php` is never committed.
4. **Open** `http://localhost/sfmtp/` and sign in.

To try it without Apache, run `php -S 127.0.0.1:8090` in this folder and open
`http://127.0.0.1:8090`. The built-in server is for local use only.

### nginx

Point `root` at this folder, pass `*.php` to PHP-FPM, and deny the private parts:

```nginx
location ~ ^/(inc|database|tests)/ { deny all; }
location ~ ^/config(\.sample)?\.php$ { deny all; }
location ~ /\. { deny all; }
```

## Demo accounts

Every account's password is **`Password123!`**.

| Email | Role |
| --- | --- |
| owner@aggfarms.test | Owner of *AGG Crop Farm* and *AGG Mixed Farm* |
| manager@aggfarms.test | Farm manager (Mixed farm) |
| agronomist@aggfarms.test | Agronomist (both farms) |
| livestock@aggfarms.test | Livestock officer (Mixed farm) |
| store@aggfarms.test | Store keeper (Mixed farm) |
| accountant@aggfarms.test | Accountant (Mixed farm) |
| worker@aggfarms.test | Field worker (both farms) |
| admin@sfmtp.test | Platform admin (approves and suspends farms) |
| supplier@aggfarms.test | Supplier portal: *Kakiri Agro-Vet Supplies*, a supplier of the Mixed farm |
| customer@aggfarms.test | Customer portal: *Kampala Millers Ltd*, a customer of the Crop farm |

**Two-step sign-in.** Owners, accountants and platform admins must set up an authenticator app
(Google Authenticator, Microsoft Authenticator, Aegis…) the first time they sign in, so have
one ready. Each gets 10 one-time recovery codes. For a quick local demo only, you can set
`'require_mfa' => false` in `config.php`.

Supplier and customer accounts do not need two-step sign-in. They see only the portal, never the
farm's own pages.

**Inviting a supplier or customer.** Go to *Portal access* (or the *Invite* link next to a supplier
or customer), enter their email address, and send them the link it shows, by email, SMS or
WhatsApp. The app does not send email itself. The link works once, for 14 days, and uses
`app_url` from `config.php`, so set that to the site's real address.

## What is in it

| Area | Pages |
| --- | --- |
| Sign-in and account | `login.php`, `mfa.php`, `mfa-setup.php`, `profile.php`, `farms.php` (switch or create a farm), `notifications.php` |
| Dashboard | `dashboard.php`: figures for your role, your tasks with check-in and check-out, work to verify, field alerts, animal health due |
| Farm structure | `structure.php`: blocks, sections, plots, stores and buildings |
| Crops | `crops.php`, `cycle.php`: crop cycles, field work and inputs (with withholding periods), field reports, stages, harvests |
| Livestock | `livestock.php`, `animal.php`: animals and groups, weights, health treatments with withdrawal periods, milk and eggs, moves and exits |
| Workers and tasks | `workers.php` (workers, attendance, leave), `tasks.php`, `task.php`: plan and assign work → start → submit → verify. Nobody verifies their own work. |
| Inventory | `inventory.php`: items, receive and issue stock at average cost, lots and expiry, movements |
| Finance | `finance.php`, `invoice.php`: expenses with an approval limit, payments, income, a double-entry journal, profit and loss, trial balance |
| Purchasing | `purchasing.php`, `po.php`: suppliers and purchase orders (draft → approve → send, with an owner limit), receiving into stock against the supplier's dispatch, supplier invoices matched to what was received, payments |
| Sales | `sales.php`, `order.php`, `invoice.php`: orders (from the portal or recorded by staff) → approve → invoice → ship from trace batches → delivered; customers, products and prices, invoices, shipments |
| Portal access | `portal-access.php`: invite a supplier or customer by a one-time link, see who has access, stop it |
| Supplier portal | `portal.php`, `supplier.php`, `supplier-order.php`: answer purchase orders, announce dispatches with the delivery note, send invoices for what the farm received, see what has been paid |
| Customer portal | `shop.php`, `customer.php`, `customer-order.php`, `customer-invoice.php`: order published products at list price, follow orders, confirm deliveries, see invoices and payments, and open the farm-to-you history of what they bought |
| Invitations | `invite.php`: accept an invitation, creating an account if needed |
| Traceability | `trace.php`, `batch.php`, `labels.php`, `q.php`: batch history with a tamper check, split/process/package, recall, publish with QR codes and printable labels, and the public scan page |
| Administration | `members.php` (people and roles), `settings.php` (farm rules), `audit-log.php`, `admin.php` (platform staff) |

## How it is built

```
*.php            one file per page
inc/             shared code: database, sign-in, permissions, ledger, stock, purchasing, orders, portals, traceability, QR, layout
assets/          style.css, app.js
database/        sfmtp.sql (schema + demo data), sfmtp-triggers.sql (optional)
tests/smoke.php  end-to-end test
```

- **Farms are kept apart.** Every farm query filters on the current farm, and a record from
  another farm is a 404. What each person can do comes from their roles on that farm (Members
  → Roles & permissions).
- **Security.**
  - Every query is a prepared statement and all output is escaped.
  - Every form has a CSRF token.
  - Sessions use HttpOnly SameSite cookies.
  - A strict Content-Security-Policy allows no inline scripts.
  - Passwords are hashed with bcrypt, and accounts lock after 5 wrong tries.
  - Authenticator secrets are encrypted with AES-256-GCM.
- **Traceability is tamper-evident.** Each batch event is chained to the one before it with
  SHA-256. *Traceability → Integrity* re-checks the whole chain.
- **Portals see only their own records.** A supplier or customer is linked to one record on
  each farm that invited them. Every portal page reads through those links and acts inside
  that one farm, so another farm, another supplier or customer, and the farm's own pages are
  out of reach. Stopping access takes effect at once and keeps the history.
- **Money is double-entry.** Every expense, payment, invoice and stock movement posts a
  balanced journal entry. Mistakes are reversed, never edited.

## Testing

Run this against a **throw-away copy** of the database, because it adds data:

```sh
php -S 127.0.0.1:8090 &          # with config.php pointing at the test database
php tests/smoke.php http://127.0.0.1:8090
```

It signs in as each demo role (setting up two-step sign-in where needed) and opens every
page. It runs the main flows:
- field work, harvest, processing, publishing and scanning a QR code;
- expenses, invoices and payments, stock and tasks;
- a purchase order through the supplier portal: dispatch, receipt, invoice and payment;
- a portal order through to delivery;
- inviting a new customer.

It also checks the role and portal boundaries.
GitHub Actions runs it on every push (`.github/workflows/sfmtp-ci.yml`).

## Earlier version

Before this, SFMTP was a Laravel API with a Next.js web app and a Flutter mobile app. That code
is still in the git history, up to commit `d64eb10`. This plain PHP version replaces it and
uses the same database design, demo data and hash chain.

Not in this version yet, planned for later rounds:
- purchase requests;
- payroll and budgets;
- report exports;
- SMS, email and payment integrations;
- the offline mobile app.
