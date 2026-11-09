# Phase 16.18 — Warehouse workflow hardening

## Clarification across turns

When Purchasing requests a draft PO **without** a destination warehouse:

1. `DraftPurchaseOrderMessageParser` extracts product, supplier, quantity, and freight when uniquely resolvable.
2. `PendingProcurementClarificationService` stores a **conversation-scoped** pending state (`pending_procurement_clarifications`).
3. The assistant asks which warehouse should receive the stock (names from `searchWarehouses` / ERP catalogue).
4. On reply (`Houston`, `Actually make it 15 slabs for Houston`, etc.), the pending handler resolves the warehouse, re-validates the draft payload, and calls `DraftPurchaseOrderService::createProposal` — **no PO is created**.

Controlled by `AI_ASSISTANT_ENFORCE_PROCUREMENT_WAREHOUSE_CLARIFICATION` and `ERP_REQUIRE_PO_LINE_WAREHOUSE`.

## Retained original request

Pending payload stores:

- Structured `draft_input` (supplier, lines, dates, freight)
- Original product and supplier ids
- Original user message (audit)

Quantity can be updated during clarification; changing to a **different product** clears pending state.

## Confirmation

Unchanged from 16.17: confirmation uses the proposal snapshot; warehouses are revalidated. If a warehouse was deleted after the proposal was created but before confirmation, confirmation fails safely.

## Historical PO referential integrity

Migration `2026_11_09_180100_restrict_warehouse_fk_on_purchase_order_lines.php` replaces `nullOnDelete` with **`restrictOnDelete`**. Warehouses referenced by `purchase_order_lines` cannot be deleted. Retire warehouses via future deactivation/archival, not physical delete.

## Limitations

- No `is_active` on warehouses.
- No goods-receipt workflow.
- Pending clarification TTL defaults to 30 minutes (`ERP_PROCUREMENT_CLARIFICATION_TTL_MINUTES`).
