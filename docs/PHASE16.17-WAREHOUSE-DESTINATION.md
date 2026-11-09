# Phase 16.17 — PO line warehouse destination

## Design

Destination is stored on **`purchase_order_lines.warehouse_id`** (nullable for historical rows), not on the PO header. One PO may include multiple lines with different destinations.

## Database

Migration `2026_11_09_170000_add_warehouse_id_to_purchase_order_lines_table.php`:

- Nullable FK → `warehouses.id` (Phase 16.18: `restrictOnDelete` — see `PHASE16.18-WAREHOUSE-WORKFLOW-HARDENING.md`)
- Index on `(warehouse_id, product_id)`

Existing lines keep `NULL` warehouse.

## Policy (`config/erp.php`)

| Key | Default | Meaning |
|-----|---------|---------|
| `require_line_destination_warehouse` | `true` | Every new proposal line must include a valid warehouse |
| `revalidate_warehouse_on_confirm` | `true` | Confirm re-checks warehouse ids from snapshot |

Warehouses have **no** `is_active` column; eligibility is existence in the catalogue only.

## Tools (Purchasing, all providers)

- `searchWarehouses` — list `{id, name, city}` from DB
- `resolveWarehouseForPurchase` — strict resolution by id, name, or city
- `proposeDraftPurchaseOrder` — each line **requires** `warehouse_id`
- `calculateReplenishmentQuantity` — **requires** `warehouse_id`; uses on-hand at that warehouse only

## Behaviour

- **Missing destination:** `DraftPurchaseOrderService` / validator reject proposal creation (AI should list warehouses and ask).
- **Invalid id:** Rejected; no silent substitute.
- **Multi-line / multi-warehouse:** Supported (same product, different warehouses, different quantities).
- **Direct PO qty:** Unchanged from Phase 16.16 (never reduced by stock).
- **Replenishment:** Warehouse-scoped shortage; Dallas stock does not affect Houston.
- **Stock:** PO create/confirm does **not** change `stock.slabs_on_hand` (no goods-receipt in this phase).

## UI

Purchasing proposal sidebar table includes a **Dest.** column (`warehouse_name`).
