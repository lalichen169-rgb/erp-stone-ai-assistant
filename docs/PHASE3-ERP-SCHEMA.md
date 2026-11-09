# Phase 3.1 — Stone ERP core schema and models

Six relational tables for inventory and purchasing in the **stone_erp_ai** database, plus Eloquent models and tests. This phase is schema and relationships only — no API, AI, seeders, or role-based access yet.

## Table overview

| Table | Purpose |
|-------|---------|
| **products** | Stone SKUs (material, color, thickness, slab dimensions) |
| **warehouses** | Physical yards or depots where slabs are stored |
| **stock** | How many slabs of each product are on hand at each warehouse |
| **suppliers** | Vendors you buy from (contact, terms; bank details encrypted) |
| **purchase_orders** | Header for a buy from a supplier (status, dates, freight) |
| **purchase_order_lines** | Line items on a PO (product, quantity, unit cost) |

**Important distinction:** `stock.slabs_on_hand` is **current inventory**. `purchase_order_lines.quantity` is **how many slabs were ordered on a PO** — receiving goods into stock is future business logic, not this migration.

## Relationships (interview-friendly)

- A **Product** can appear in many **Stock** rows (one per warehouse) and on many **PurchaseOrderLine** rows over time.
- A **Warehouse** has many **Stock** rows (one row per product in that warehouse).
- **Stock** belongs to exactly one **Product** and one **Warehouse**; the pair is unique.
- A **Supplier** has many **PurchaseOrders**.
- A **PurchaseOrder** belongs to one **Supplier** and has many **PurchaseOrderLine** rows.
- A **PurchaseOrderLine** belongs to one **PurchaseOrder** and one **Product**.

```text
Supplier 1──* PurchaseOrder 1──* PurchaseOrderLine *──1 Product
                                                      *
Warehouse 1──* Stock *──1 Product
```

## Columns and constraints

### products

- Unique **sku**; searchable indexes on **material**, **color**, **thickness_cm**.
- **thickness_cm**: `decimal(5,2)`.

### warehouses

- Indexes on **name** and **city**.

### stock

- **Unique (product_id, warehouse_id)** — one balance row per product per warehouse.
- **slabs_on_hand**: unsigned integer, default 0; **CHECK (slabs_on_hand >= 0)** on MySQL 8.4 (added via raw `ALTER TABLE` in the migration because Blueprint has no `check()` in this Laravel version).
- Foreign keys to products and warehouses: **RESTRICT** on delete (no silent removal of inventory rows when master data is deleted).

### suppliers

- **contact**: nullable text; **payment_terms**: nullable string.
- **bank_details**: nullable **text** (stores Laravel-encrypted payload).

### purchase_orders

- **status**: string (not DB enum) — see `App\Enums\PurchaseOrderStatus`: `draft`, `ordered`, `received`, `cancelled`.
- **order_date**, nullable **eta**; **freight_cost** `decimal(12,2)` default 0.
- Indexes on **status**, **order_date**, **eta**; **supplier_id** indexed via foreign key.
- **RESTRICT** on delete for supplier (cannot delete a supplier that still has POs).

### purchase_order_lines

- **quantity** unsigned integer; **unit_cost** `decimal(12,2)`.
- Indexes on **purchase_order_id** and **product_id**.
- **RESTRICT** on delete for both PO and product — preserves line history; delete lines or POs only through explicit application rules later.

## Supplier bank_details encryption

Bank details use Laravel’s **`encrypted` cast** on `Supplier` (`'bank_details' => 'encrypted'`). Values are encrypted with `APP_KEY` when saved and decrypted when read on the model. The attribute is in **`$hidden`** so it does not appear in `toArray()` / JSON serialization by default.

Do not log `bank_details` or duplicate encryption in controllers.

## Purchase order status as string

Statuses are stored as plain strings so new values can be added without DB migrations. Allowed values for Phase 3.1 are documented in `App\Enums\PurchaseOrderStatus`. Status transition rules belong in a later service layer.

## Foreign-key delete behavior (summary)

| Child | Parent | On delete |
|-------|--------|-----------|
| stock | product, warehouse | RESTRICT |
| purchase_orders | supplier | RESTRICT |
| purchase_order_lines | purchase_order, product | RESTRICT |

## Assumptions

- Table name **`stock`** (singular) matches the challenge; Laravel model `Stock` sets `$table = 'stock'`.
- PO line **quantity** counts **slabs** ordered, same unit as **slabs_on_hand** (not square meters in this schema).
- No soft deletes in Phase 3.1.
- Failed jobs and Laravel queue tables remain on MySQL; ERP tests use `RefreshDatabase` (transaction rollback on MySQL where supported).

## Migrations

Files under `database/migrations/2026_11_06_120000_*` through `120005_*`, run after default Laravel migrations.

## Models

`App\Models\Product`, `Warehouse`, `Stock`, `Supplier`, `PurchaseOrder`, `PurchaseOrderLine` — see source for relationships and casts.
