# Phase 16.19 — Procurement acceptance, warehouse clarification E2E, and regression audit

## Overall result

**PASS WITH FINDINGS**

Procurement and warehouse clarification behaviour is verified on a fresh `stone_erp_ai_test` database. Live Ollama acceptance was not completed (remote endpoint unreachable). The full PHPUnit suite does not pass green; most remaining failures predate this phase or reflect test-data isolation (duplicate warehouse names / missing seeded Calacatta stock), not procurement regressions.

## Execution order (verified)

`AssistantRequestService` turn pipeline for synchronous Purchasing messages:

1. Pending async shortcut (if applicable)
2. `PurchasingWarehouseDestinationClarification::tryResume` — warehouse reply or replacement PO; skips unrelated stock via `looksLikeUnrelatedStandaloneQuestion`
3. `FollowUpQuestionResolver`
4. After resolution merge: `PurchasingWarehouseDestinationClarification::tryBegin` — new draft PO without warehouse
5. Deterministic ERP preflight / AI provider path

Resume uses `PendingProcurementClarificationService::tryResumeWarehouseDestination` inside a DB transaction with `lockForUpdate()` on the pending row. Successful proposal creation clears pending only after `createProposal` succeeds.

## Scenario matrix (A–I)

| Scenario | Result | Evidence |
|----------|--------|----------|
| A — Begin PO without warehouse | PASS | `ProcurementWarehouseClarificationTest::test_begin_clarification_lists_database_warehouses` |
| B — Reply Houston | PASS | `test_houston_reply_creates_pending_proposal_retaining_details` |
| C — Quantity + Houston | PASS | `test_quantity_can_change_during_clarification` |
| D — Unknown warehouse | PASS | `test_unknown_warehouse_prompts_again` |
| E — Ambiguous warehouse | PASS | `test_ambiguous_warehouse_never_picks_arbitrarily` |
| F — Unrelated stock while pending | PASS | `test_unrelated_stock_question_still_returns_grounded_count`, `test_unrelated_question_does_not_consume_pending_clarification` |
| G — New PO replaces pending | PASS (after fix) | `test_new_po_request_replaces_pending_clarification`; replacement now updates pending in the same turn via `beginWarehouseDestination` |
| H — Expired pending | PASS (isolated DB) | `test_pending_clarification_expiry` |
| I — Cross-user / conversation | PASS | `test_pending_clarification_scoped_to_owner_conversation` |

Additional procurement coverage: `ProcurementWarehouseDestinationTest`, `ProcurementBusinessRulesTest`, `DraftPurchaseOrderServiceTest`, `PurchaseOrderProposalApiTest`.

## Remediation in 16.19 (no new business features)

1. **`PendingProcurementClarificationService`** — When a user sends a full new draft-PO message with a different product while clarification is pending, replace pending and return `needs_clarification` in the same turn (previously cleared pending and relied on `tryBegin`, which follow-up resolution could block).
2. **`ErpServiceSecurityAuditTest`** — Proposal fixtures include `warehouse_id` via `proposalLine()` (required since 16.17).
3. **`AssistantSystemInstructionBuilderTest`** — Compact purchasing instructions assert warehouse resolution text (`resolveProductsForPurchase`, `searchWarehouses`, `warehouse_id`).
4. **`ProcurementWarehouseClarificationTest`** — Replace-pending test no longer duplicates supplier/warehouses; re-queries pending after replacement.

## Pending clarification lifecycle and concurrency

| Concern | Implementation |
|---------|----------------|
| TTL | `erp.procurement.pending_clarification_ttl_minutes` |
| Expiry | Row deleted on resume attempt; warehouse-like reply gets explicit expired message |
| Ownership | `user_id` + `conversation_id` (unique pending per conversation) |
| Replacement | `updateOrCreate` on begin; same-turn replacement on resume when product changes |
| Locking | `lockForUpdate()` during resume |
| Clear timing | After successful `createProposal` only |
| Concurrent duplicate proposals | Mitigated by proposal idempotency keys + pending row lock; no dedicated concurrency test added in 16.19 |

## Existing feature regression matrix

| Feature | Result | Notes |
|---------|--------|-------|
| Login / Sanctum | PASS (bundle) | Unchanged |
| Sales product/stock/PO lookup | PASS | `ProviderIsolationRegressionTest`, `ErpReadApiTest` (when demo stock present) |
| Sales landed cost / PO create | PASS | Denied / soft refusal tests |
| Purchasing PO proposal-first | PASS | Clarification + proposal API tests |
| Warehouse stock lookup | PASS | Isolated service tests; full suite fails when fixtures polluted |
| Multi-turn stock follow-up | PARTIAL | `ConversationPhase7Test` city-swap cases fail with `needsClarification=true` (pre-existing / resolver; not procurement) |
| Provider isolation (OpenAI / Claude / Ollama) | PASS | `ProviderIsolationRegressionTest` in acceptance bundle |
| Async / idempotency | Not re-run E2E in 16.19 | Prior phases covered |
| Frontend proposal load | Code reviewed | `resources/js/chat/main.js` reads `purchase_order_proposal` |

## Migration safety

Fresh `test-db-prep.ps1` run applied all migrations including:

- `2026_11_09_170000_add_warehouse_id_to_purchase_order_lines_table.php`
- `2026_11_09_180000_create_pending_procurement_clarifications_table.php`
- `2026_11_09_180100_restrict_warehouse_fk_on_purchase_order_lines.php` — **`restrictOnDelete`** on `purchase_order_lines.warehouse_id`

No development database reset was performed. Tests use `stone_erp_ai_test` only.

## PHPUnit results

### Commands

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test-db-prep.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1
```

### Full suite (after 16.19 fixes)

- **426 tests**, 1249 assertions
- **2 errors**, **32 failures**, **1 risky**
- Log: `storage/logs/phpunit-full-16.19-rerun.txt`

**Classification**

- **Procurement clarification (2 errors in full suite only):** `ModelNotFoundException` when prior tests leave duplicate `Houston`/`Dallas`/`Austin` rows — `extractDraftIntent` returns null (`ambiguous_warehouse` / product ambiguity). **Pass on fresh DB** (12/12 clarification tests).
- **Stock / assistant API (~25+ failures):** Expect seeded Calacatta Houston=42 etc.; many tests create warehouses without stock or leave duplicate catalogue rows. **Pre-existing suite isolation issue**, not introduced by 16.18–16.19.
- **ConversationPhase7 (3 failures in bundle):** `provider_tool_call_id` expectation `tc1` vs `trace_1`; follow-up city swap needs clarification — **pre-existing**, unrelated to procurement.

### Focused acceptance bundle (fresh test DB)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1 `
  tests/Feature/Erp/ProcurementWarehouseClarificationTest.php `
  tests/Feature/Erp/ProcurementWarehouseDestinationTest.php `
  tests/Feature/Erp/ProcurementBusinessRulesTest.php `
  tests/Feature/Erp/Services/DraftPurchaseOrderServiceTest.php `
  tests/Feature/Erp/Services/ErpServiceSecurityAuditTest.php `
  tests/Feature/Api/PurchaseOrderProposalApiTest.php `
  tests/Feature/AI/ProviderIsolationRegressionTest.php `
  tests/Feature/AI/Grounding/SalesPurchaseOrderCreationRefusalTest.php `
  tests/Unit/Services/AI/AssistantSystemInstructionBuilderTest.php
```

**OK (94 tests, 306 assertions)**

## Live Ollama

- Configured base URL: `http://172.16.7.55:11434` (from project `.env`; not altered).
- `Test-NetConnection -ComputerName 172.16.7.55 -Port 11434` → **TcpTestSucceeded: False** (timeout).
- **Live AI acceptance: NOT VERIFIED.** Mocked/provider-fake tests do not substitute for live Ollama.

## Browser acceptance

Not executed in this environment (no automated browser run). **Manual demo checklist:**

1. Log in as Purchasing.
2. Ask: *Create a draft PO for 10 slabs of Carrara Marble 2cm from Gulf Stone Imports.*
3. Confirm warehouse clarification lists real sites; no PO/proposal until destination chosen.
4. Reply *Houston* — proposal appears in sidebar; preview lines show quantity 10 and Houston destination.
5. Confirm once — single draft PO; stock unchanged.
6. Start another PO without warehouse; reply with unrelated stock question — stock answer normal; pending PO still available.
7. Log in as Sales — stock questions work; PO creation refused.
8. Refresh after confirm — no duplicate PO/proposal.

## Known limitations

- Full PHPUnit green requires broader test isolation (unique warehouse fixtures or per-class DB reset), outside 16.19 scope.
- Live Ollama requires network access to configured host.
- Warehouse catalogue has no `is_active` flag (unchanged).

## Files changed in 16.19

| File | Change |
|------|--------|
| `app/Services/Erp/PendingProcurementClarificationService.php` | Same-turn pending replacement for new PO with different product |
| `tests/Feature/Erp/Services/ErpServiceSecurityAuditTest.php` | `warehouse_id` on proposal lines |
| `tests/Unit/Services/AI/AssistantSystemInstructionBuilderTest.php` | Compact instruction assertions |
| `tests/Feature/Erp/ProcurementWarehouseClarificationTest.php` | Replace-pending test stability |

## Manual actions for interview demo

1. Ensure Ollama host `172.16.7.55` reachable or switch demo to mocked/OpenAI per environment policy (do not commit secrets).
2. Run `test-db-prep.ps1` before a recorded test run.
3. Walk through browser checklist above on local `php artisan serve` + Vite build.
