# Phase 4.1 — ERP business services (read-only)

Application services sit between future API/AI layers and Eloquent models. They enforce **Laravel Gates**, return **structured arrays** (not full models with hidden cost fields), and keep **read-only** behavior for this phase.

## Why services instead of direct DB access

- **Authorization** is centralized (`Gate::forUser($user)`), so Sales cannot reach costs even if an AI tool misbehaves.
- **Business rules** (overdue POs, landed cost) live in one place for tests and interviews.
- **Stable response shapes** help the AI layer map natural-language answers without leaking `unit_cost`, `freight_cost`, or encrypted supplier data.

## Services overview

| Service | Responsibility | Abilities required |
|---------|----------------|-------------------|
| `StockLookupService` | Product search; stock by warehouse or all warehouses | `viewProducts`, `viewStock` |
| `PurchaseOrderLookupService` | PO search by supplier, status, dates, product SKU/name | `viewPurchaseOrderStatus` |
| `OverduePurchaseOrderService` | Ordered POs with ETA before reference date | `viewPurchaseOrderStatus` |
| `LandedCostService` | Value-based freight allocation and landed unit cost | `viewLandedCosts` |

All methods accept an authenticated `App\Models\User` as the first argument so they work outside HTTP requests.

## StockLookupService

Read-only inventory and catalog lookups.

**Public methods:**

- `searchProducts(User, filters, limit)` — SKU, name, material, color, thickness (no duplicate rows; no joins).
- `stockForProductAtWarehouse(User, productId, warehouseId)` — `slabs_on_hand` **0** when no `stock` row exists.
- `stockForProductAtWarehouseByAttributes(User, productFilters, warehouseFilters)` — e.g. Calacatta + 3cm + Houston.
- `stockForProductAllWarehouses(User, productId)` — every warehouse with 0 when no row.
- `searchStockLevels(User, productFilters, warehouseFilters, limit)` — one entry per product/warehouse pair.

Example (future controller or AI tool):

```php
$result = app(StockLookupService::class)->stockForProductAtWarehouseByAttributes(
    $user,
    ['material' => 'Quartz', 'color' => 'Calacatta', 'thickness_cm' => 3],
    ['name' => 'Houston'],
);
// $result['slabs_on_hand'] === 42 for demo seed data
```

## PurchaseOrderLookupService

Read-only PO headers and lines **without** `unit_cost` or `freight_cost`.

**Public methods:**

- `search(User, filters, limit, page)` — pagination meta included; max limit 200.

Filters: `supplier_id`, `supplier_name`, `status`, `order_date_from/to`, `eta_from/to`, `product_sku`, `product_name`.

Purchase orders have **no warehouse relationship**; inventory remains on `stock` only.

## OverduePurchaseOrderService

**Definition (exact):** A PO is overdue when:

1. `status` is **`ordered`**, and  
2. `eta` is **not null**, and  
3. `eta` is **strictly before** the reference date (default: today).

Draft, received, and cancelled POs are **never** overdue, even with a past ETA. Null ETA is not overdue.

**Public methods:**

- `listOverdue(User, ?today, limit)` — includes `days_overdue`.
- `isOverdue(PurchaseOrder, ?today)` — single-record check for tests and future workflows.

Pass `Carbon::parse('2026-11-06')` as `$today` in tests to fix “today”.

## LandedCostService

**Purchasing only** (`viewLandedCosts`). Does **not** update POs or lines.

For each line:

- `line_value = quantity × unit_cost`
- `total_line_value = sum(line_value)`
- `line_freight_share = freight_cost × (line_value / total_line_value)`
- `freight_per_unit = line_freight_share / quantity`
- `landed_unit_cost = unit_cost + freight_per_unit`

### Decimal safety and rounding

- Calculations use **BCMath** via `App\Services\Erp\ErpMoney` (scale 8 internally).
- Currency fields are rounded **half-up to 2 decimal places**.
- After rounding each line’s `freight_share`, any **remainder** vs PO `freight_cost` is applied to the line with the **largest line value** so allocated freight **reconciles** to the header freight.
- **Zero total line value** → `LandedCostCalculationException` (no division by zero).
- **Quantity ≤ 0** → `LandedCostCalculationException`.
- Taxes, duties, and insurance are **out of scope** (not in schema).

**Public methods:**

- `calculateForPurchaseOrder(User, purchaseOrderId)`

Example:

```php
$breakdown = app(LandedCostService::class)->calculateForPurchaseOrder($purchasingUser, $poId);
// $breakdown['lines'][0]['landed_unit_cost'], freight_share, reconciliation flags
```

## Authorization

Each service calls `Gate::forUser($user)->authorize(...)` before querying. Sales receives `403` from `LandedCostService` even when invoked directly. Do not rely on controllers alone.

## Related docs

- `docs/PHASE3-AUTHORIZATION.md` — ability matrix  
- `docs/PHASE3-ERP-SCHEMA.md` — tables and relationships  
- `docs/PHASE3-SEEDERS.md` — demo data for manual checks  
