# Phase 16.22 — Supplier-specific delayed / overdue purchase order queries

**Date:** 2026-11-09  
**Agent:** Cursor Composer 2.5  

## Overall result

**PASS** — Feature was **partially implemented** before this phase; targeted changes added supplier filtering, Purchasing deterministic disclosure, and Sales soft denial.

## Initial audit (A–L)

| Item | Finding |
|------|---------|
| A. Purchasing overdue search | Yes — `OverduePurchaseOrderService` + `findOverduePurchaseOrders` |
| B. Supplier filter on overdue | **No** (pre-16.22) |
| C. Combined supplier + overdue in one request | **No** at service/tool level |
| D. Supplier ID resolution | Partial — only via `searchPurchaseOrders` / PO search, not overdue |
| E. Supplier-scoped overdue results | **No** |
| F. Overdue rule | Yes — ordered status, non-null ETA, ETA &lt; reference date |
| G. Excludes received/cancelled/draft | Yes — status must be `ordered` |
| H. Sales tool access | Yes — `allowedFor` included Sales for **all** overdue queries |
| I. Sales supplier-specific soft denial | **No** (pre-16.22) |
| J. Sales individual PO status | Yes — `PurchaseOrderSearchReply` + `searchPurchaseOrders` |
| K. Provider tool parity | Same `FindOverduePurchaseOrdersTool` definition for OpenAI/Claude/Ollama |
| L. Follow-up supplier swap | Yes — `DeterministicFollowUpResolver::expandOverdueSupplierFollowUp` (existing tests) |

## Changes made (16.22)

1. **`OverduePurchaseOrderService::listOverdue`** — optional `supplier_id` filter (rule unchanged).
2. **`FindOverduePurchaseOrdersTool`** — `supplier_id` / `supplier_name`; Sales blocked when supplier filter present; name resolution via `SupplierFilterResolution`.
3. **`OverduePurchaseOrderQuestionParser`** — overdue/delayed intent + supplier-specific detection (excludes aggregate “which suppliers” queries).
4. **`OverduePurchaseOrderSearchReply`** — Purchasing deterministic ERP replies (supplier-scoped and general overdue).
5. **`SalesSupplierOverduePurchaseOrderAnalysisRefusal`** — deterministic Sales soft denial (exact message per spec).
6. **`AssistantRequestService`** — early Sales refusal + Purchasing overdue reply before PO search.
7. **`PurchaseOrderSearchReply`** — skips overdue intents (no overlap with overdue handler).
8. **`config/ai.php`** — `enforce_overdue_purchase_order_disclosure`, `enforce_sales_supplier_overdue_po_analysis_refusal`.

## Purchasing behaviour

- Questions such as “Which purchase orders from Brazos Marble LLC are delayed?” resolve supplier name against the database, apply the existing overdue rule, and return PO #, supplier, status, ETA, and days overdue.
- Unknown supplier → clear “no supplier matched” message.
- Ambiguous supplier → clarification listing candidate names.
- No matching overdue POs → explicit empty message for that supplier.
- General overdue (no named supplier) still supported for Purchasing via the same reply handler.

## Sales behaviour

- **Supplier-specific** delayed/overdue analysis → deterministic soft denial (no tool call).
- **Individual PO status** (e.g. PO-507) → unchanged.
- **General PO listing** → unchanged.
- **Non-supplier-specific overdue** listing via tool → still allowed (same as pre-16.22); only supplier-filtered overdue analysis is restricted.

### Soft denial text

> Supplier-specific delayed purchase order analysis is available to the Purchasing role only. You can still check individual purchase-order statuses and view existing purchase-order information.

## Overdue business rule (unchanged)

- Status must be `ordered`.
- `eta` must not be null.
- `eta` date strictly before reference date (default: today).
- Received, cancelled, draft, and null-ETA orders are never overdue.

## Tool permissions

| Tool | Sales | Purchasing |
|------|-------|------------|
| `findOverduePurchaseOrders` (no supplier filter) | Allowed | Allowed |
| `findOverduePurchaseOrders` (supplier_id / supplier_name) | **Denied** (`permission_denied`) | Allowed |
| `searchPurchaseOrders` | Allowed | Allowed |

## Tests

- `tests/Feature/AI/Grounding/SupplierOverduePurchaseOrderPhase16_22Test.php` (7 cases)
- `tests/Feature/Erp/Services/OverduePurchaseOrderServiceTest.php` — supplier filter case added
- Existing: `DeterministicFollowUpResolverTest::test_overdue_supplier_short_follow_up`, `OverduePurchaseOrderServiceTest`, `ToolLayerTest::test_find_overdue_preserves_service_rules`

## Limitations

- Aggregate Purchasing questions such as “Which suppliers have delayed purchase orders?” rely on model tool orchestration or future deterministic grouping (not added in 16.22).
- HTTP `GET /api/purchase-orders/overdue` unchanged (no supplier query param) — chat/tool path is authoritative for this phase.
- Live Ollama not tested in this phase.

## Confirmations

- Ollama not modified or tested.
- Development database not reset.
- No Git commit or push.
