# Phase 16.16 — Procurement business rules

## Product resolution

`ProductCatalogResolutionService` provides **strict** catalogue resolution for purchasing:

- **unique_match** — one catalogue row satisfies all filters.
- **ambiguous_product** — multiple rows; returns `alternatives` and `clarification_required`.
- **missing_thickness** — multiple thicknesses without `thickness_cm`.
- **unmatched_attribute** — e.g. requested material has no matching row (no silent material relaxation).
- **not_found** — no rows match.

Tools: `resolveProductsForPurchase` (Purchasing only). Stock `checkStock` / Ollama `searchProducts` retain existing behaviour.

## Supplier–product catalogue

Table: `supplier_products` (`supplier_id`, `product_id`, optional `supplier_sku`, optional `unit_cost`, `is_active`).

- Many-to-many; unique `(supplier_id, product_id)`.
- No auto-seeded mappings; configure via application data.
- Config `erp.procurement.enforce_supplier_product_catalog` (default **false** for backward compatibility; set **true** in production when catalogue is populated).

Tool: `validateSupplierProductOffering`.

## Quantities

- **Direct PO**: `proposeDraftPurchaseOrder` / `DraftPurchaseOrderService` preserve the requested line quantity (never subtract on-hand stock).
- **Replenishment**: `calculateReplenishmentQuantity` only when the user asks for target stock / replenishment.  
  `shortage = max(0, target_quantity - sum(slabs_on_hand))` across warehouses; no incoming PO accounting.

## Pricing and freight

`ProcurementProposalValidator` enforces:

- Positive integer quantities up to `erp.procurement.max_line_quantity`.
- Freight via `validateFreight` (non-negative; zero allowed when `allow_zero_freight` is true).
- When catalogue enforcement is on: active mapping required; catalogue `unit_cost` must match proposal line cost when configured.

Proposal payload lines include `unit_cost_source` and `supplier_product_id` metadata. Confirmation revalidates catalogue when `revalidate_catalog_on_confirm` is true.

## Destination warehouse

`purchase_orders` has **no** destination warehouse field (unchanged). No warehouse is inferred for PO creation.

## Workflow (unchanged safeguards)

1. NL → tools resolve IDs and validate server-side.
2. `proposeDraftPurchaseOrder` → pending proposal only.
3. User confirms via API → `DraftPurchaseOrderService::confirmProposal` (transaction + row lock + idempotency).

## Configuration (`config/erp.php` → `procurement`)

| Key | Purpose |
|-----|---------|
| `enforce_supplier_product_catalog` | Require active supplier-product row |
| `allow_manual_unit_cost_when_catalog_missing` | Manual unit cost when mapping has null price |
| `require_catalog_unit_cost_when_configured` | Line cost must match catalogue price |
| `revalidate_catalog_on_confirm` | Re-check mappings at confirm |
| `max_line_quantity` | Upper bound per line |
| `allow_zero_freight` | Permit zero freight |

## Tests

`tests/Feature/Erp/ProcurementBusinessRulesTest.php` — resolution, catalogue, quantity, replenishment, tools.

Run (Windows):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test-db-prep.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 tests/Feature/Erp/ProcurementBusinessRulesTest.php
```
