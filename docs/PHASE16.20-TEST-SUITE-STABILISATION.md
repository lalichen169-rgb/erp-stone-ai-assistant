# Phase 16.20 — Test suite stabilisation

## Overall result

**PASS** — Full PHPUnit suite green on dedicated test database.

| Metric | Phase 16.19 baseline | Phase 16.20 final |
|--------|----------------------|-------------------|
| Tests | 426 | 426 |
| Assertions | 1249 | 1325 |
| Errors | 2 | 0 |
| Failures | 32 | 0 |
| Risky | 1 | 0 |

Log: `storage/logs/phpunit-full-16.20.txt`

## Root cause summary

| Category | Root cause | Fix |
|----------|------------|-----|
| Procurement / warehouse failures in full suite | Tests without DB rollbacks (e.g. `DeterministicFollowUpResolverTest`) committed seed data; duplicate Houston rows and extra Calacatta products broke resolution | Suite-wide `DatabaseTransactions` on `tests/TestCase.php` |
| Stock / tool / API failures | Polluted catalogue + ambiguous warehouses | Transaction isolation + idempotent `firstOrCreate` / `updateOrCreate` fixtures |
| Provider API tool-call tests | Deterministic stock / landed-cost grounding answered before mocked provider ran | Disable grounding only in tests that assert provider tool traces |
| `ToolLayerTest` risky test | No assertion on success path | Seed stock + assert `slabs_on_hand` when execution succeeds |
| `ConversationPhase7Test` city follow-ups | `DeterministicFollowUpResolver` requires registered warehouse rows; tests omitted fixture | `createHoustonDallasAustinWarehouses()` in city-swap tests |
| `trace_1` vs `tc1` | Polluted DB caused `ConversationToolCall::first()` to read wrong row | Resolved by transaction isolation (no assertion change) |

Application ERP, procurement, permissions, and provider logic were **not** changed for test convenience.

## Failure inventory (16.19 → resolution)

All 35 issues from `storage/logs/phpunit-full-16.19-rerun.txt`:

1. **ProcurementWarehouseClarificationTest** (10 failures + 2 errors) — fixture pollution / duplicate warehouses; **fixed by isolation + idempotent warehouses**.
2. **StockLookupServiceTest** (6 failures) — duplicate products from committed seeds; **fixed by isolation**.
3. **ToolLayerTest** (8 failures + 1 risky) — missing stock + pollution; **fixture + assertion fix**.
4. **ToolExecutionIsolationTest** (3 failures) — wrong product id / failed checkStock; **fixed by isolation**.
5. **AssistantApiTest** (5 failures) — grounding bypassed mocked tools or product ambiguity; **grounding disabled in provider tool tests + isolation**.
6. **ProviderCompatibilityAuditTest** (1 failure) — same as AssistantApi follow-up; **grounding disabled**.
7. **ErpReadApiTest** (1 failure) — stock 0 from ambiguous warehouse; **fixed by isolation**.
8. **ProcurementWarehouseDestinationTest** (1 failure) — ambiguous Houston; **fixed by idempotent warehouses**.
9. **ConversationPhase7Test** (2 failures after isolation) — missing warehouse seed; **explicit warehouse fixture**.

## Files changed

| File | Why |
|------|-----|
| `tests/TestCase.php` | Apply `UsesErpDatabaseTransactions` to every test |
| `tests/Support/CreatesErpServiceFixtures.php` | Idempotent warehouses/product; `disableAssistantStockGroundingForProviderToolTests()` |
| `tests/Feature/Erp/Services/StockLookupServiceTest.php` | Reuse fixture Houston row |
| `tests/Feature/AI/Tools/ToolLayerTest.php` | Risky test assertions |
| `tests/Feature/Api/AssistantApiTest.php` | Grounding flags for provider round-trip tests; Ollama async test |
| `tests/Feature/AI/ProviderCompatibilityAuditTest.php` | Grounding off for OpenAI/Claude tool tests |
| `tests/Feature/AI/Conversations/ConversationPhase7Test.php` | Warehouse catalogue for city follow-ups |

## Database safety

- PHPUnit uses `stone_erp_ai_test` (`phpunit.xml` `DB_DATABASE`).
- `tests/TestCase.php` refuses any other database name in testing.
- `test-db-prep.ps1` resets **test** schema only; development DB `stone_erp_ai` was not reset.
- Verified via `php artisan db:show` (both DB names listed; no credentials logged).

## Commands

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test-db-prep.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\test.ps1
```

Procurement acceptance bundle (16.19):

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

**OK (94 tests, 306 assertions)** after stabilisation.

## ConversationPhase7Test

- **`trace_1` vs `tc1`:** Obsolete symptom of global DB pollution; passes with per-test rollback.
- **City follow-up `needsClarification`:** Correct behaviour when no warehouse rows exist; tests now seed Houston/Dallas/Austin before resolver exercise.

## Business behaviour preserved

No changes to application services for procurement, warehouse clarification, Sales/Purchasing gates, or provider implementations. Test-only configuration toggles (`enforce_stock_grounding`, `enforce_purchasing_landed_cost_disclosure`) apply only inside tests that document provider tool round-trips.

## Remaining limitations

- Tests that intentionally validate **deterministic stock grounding** still run with grounding enabled (e.g. `StockCountGroundingTest`).
- Redis integration test still skips when Redis is unreachable (unchanged).
- No Git commit or push performed in this phase.
