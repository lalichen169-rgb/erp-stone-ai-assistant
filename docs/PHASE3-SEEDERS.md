# Phase 3.3 — ERP seeders and demo users

Idempotent demo data for local development and natural-language ERP testing. All demo SKUs are prefixed with **`DEMO-`**; suppliers are prefixed with **`Demo Supplier —`**.

## What is seeded

| Seeder | Content |
|--------|---------|
| **ProductSeeder** | 22 stone/quartz/marble/granite products (2 cm and 3 cm, varied colors) |
| **WarehouseSeeder** | Houston, Dallas, Austin |
| **SupplierSeeder** | 5 fictional suppliers with demo contacts, terms, and **encrypted** bank details |
| **StockSeeder** | Inventory across warehouses (Calacatta 3 cm Quartz: **42 Houston**, **18 Dallas**) |
| **PurchaseOrderSeeder** | 16 demo POs (draft, ordered, received, cancelled) with lines, freight, and ETAs |
| **DemoUserSeeder** | **Opt-in** — two demo users (see below) |

## Challenge inventory example

Product **DEMO-STQ-CAL-30**:

- Material: **Quartz**
- Color: **Calacatta**
- Thickness: **3.00 cm**

Stock:

- **Houston:** 42 slabs  
- **Dallas:** 18 slabs  

## Purchase orders and “overdue” samples

Demo POs use a stable key **`(supplier_id, order_date)`** so reruns update the same headers.

Several **`ordered`** POs have **ETAs before 2026-11-06** (for future overdue logic). **Draft**, **received**, and **cancelled** POs with old ETAs are included so they must **not** be treated as overdue later.

Statuses and freight/unit costs are set for landed-cost testing in a later phase.

## Fictional data

Supplier names, contacts, payment terms, and bank strings are **fabricated** (`.example` / demo routing numbers). Bank details are saved only through the **Supplier model** so Laravel **encrypts** them at rest.

## Demo users (local only)

| Email | Role |
|-------|------|
| `sales@example.test` | sales |
| `purchasing@example.test` | purchasing |

### Password configuration

Set in your **local `.env` only** (never commit, never document the actual value):

```env
DEMO_USER_PASSWORD=your-chosen-local-password
```

If `DEMO_USER_PASSWORD` is missing or empty, **DemoUserSeeder fails** with a clear message.

- Passwords are stored **hashed** (User model `hashed` cast).
- **Rerunning** DemoUserSeeder does **not** change passwords for existing demo users.
- Roles are set on create; existing demo users are aligned to the expected role without downgrading other accounts.

Do **not** run DemoUserSeeder in production deployments.

## How to run seeders

From the project root with project-local PHP:

```powershell
.\scripts\dev.cmd php artisan db:seed --no-interaction
```

ERP data only (no demo users).

Demo users (after `DEMO_USER_PASSWORD` is set in `.env`):

```powershell
.\scripts\dev.cmd php artisan db:seed --class=Database\Seeders\DemoUserSeeder --no-interaction
```

Safe to **rerun** — demo products, warehouses, suppliers, stock, and POs **update** existing demo rows instead of duplicating them.

## What is not done

- No `migrate:fresh`, truncate, or mass delete of unrelated rows  
- No API tokens  
- No changes to ERP schema  

## Related docs

- `docs/PHASE3-ERP-SCHEMA.md`  
- `docs/PHASE3-AUTHORIZATION.md`  
