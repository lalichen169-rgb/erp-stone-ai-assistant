# Phase 4.3 — ERP service layer security and reliability audit

Audit of Phase 4.1 read services and Phase 4.2 draft PO proposal workflow. No API or AI changes.

## Areas reviewed

- `StockLookupService`, `PurchaseOrderLookupService`, `OverduePurchaseOrderService`, `LandedCostService`, `ErpMoney`
- `DraftPurchaseOrderService`, `PurchaseOrderProposal` model and migration
- `ErpAbilities`, `ErpGateRegistrar`, `AuthorizesErpUser`
- Related enums, tests, `phpunit.xml`, RefreshDatabase behavior
- Documentation: `PHASE3-AUTHORIZATION.md`, `PHASE4-ERP-SERVICES.md`, `PHASE4-DRAFT-PO-WORKFLOW.md`

## Issues found and severity

| ID | Severity | Finding |
|----|----------|---------|
| A1 | **High** | Laravel `RefreshDatabase` runs `migrate:fresh` once per process on the configured database. The suite previously used `RefreshDatabase` with `.env` `stone_erp_ai`, which could **wipe development ERP data** on the first test run. |
| A2 | **High** | `confirmProposal()` did not verify `payload_hash` against stored `payload`. Tampered JSON (e.g. raw SQL update) could confirm invalid quantities/costs. |
| A2b | **Medium** | Hash compared raw JSON encoding; MySQL JSON round-trip could change numeric shapes and cause false integrity failures — fixed via canonical normalization before hash. |
| A3 | **Medium** | `PurchaseOrderProposal` allowed Eloquent `update()` on `payload` / `payload_hash` via `$fillable`. |
| A4 | **Low** | Concurrent `createProposal()` with the same idempotency key could raise an uncaught duplicate-key exception instead of returning the existing proposal. |
| A5 | **Low** | `PHASE3-AUTHORIZATION.md` stated tests “do not wipe `stone_erp_ai` destructively”; that was inaccurate for default RefreshDatabase + shared MySQL. |
| A6 | **Info** | True multi-connection MySQL concurrency (two sessions confirming one proposal) was **not** exercised in CI; sequential tests cover idempotent confirm and transactions. |

No issues found requiring changes to overdue definition, PO lookup cost hiding, or landed-cost formula (BCMath already used).

## Fixes implemented

| Issue | Fix |
|-------|-----|
| A1 | Replaced `RefreshDatabase` with **`UsesErpDatabaseTransactions`** (per-test rollback, no `migrate:fresh`). `tests/TestCase.php` allowlists DB names (`stone_erp_ai`, `stone_erp_ai_test`, SQLite paths). Added `.env.testing.example`. |
| A1 (support) | Stock migration CHECK constraint runs **only on MySQL** (project PHP has `pdo_mysql` only, no SQLite driver). |
| A2 | `assertPayloadIntegrity()` before PO creation on confirm; uses `hash_equals`, `normalizePayloadForHash()`, and stable `json_encode` flags. |
| A3 | Model `updating` hook rejects changes to `payload` / `payload_hash`. |
| A4 | Catch MySQL duplicate key on create; re-fetch and apply idempotency rules via `resolveIdempotentCreateConflict()`. |

## Tests added

`tests/Feature/Erp/Services/ErpServiceSecurityAuditTest.php`:

- Service authorization matrix (Sales vs Purchasing)
- Sales denied landed cost service
- Proposal snapshot survives supplier/product renames; confirm uses snapshot values
- Tampered payload (raw DB) fails integrity on confirm; no PO created
- Eloquent payload update blocked
- PO lookup max pagination cap
- Large monetary landed-cost reconciliation
- Stock lookup still requires authorized user

Existing Phase 4.1 / 4.2 tests retained (lifecycle, idempotency, rollback, etc.).

## Authorization matrix (service layer)

| Operation | Ability / rule | Sales | Purchasing |
|-----------|----------------|-------|------------|
| Stock / product search | `viewProducts` + `viewStock` | Yes | Yes |
| PO search / overdue | `viewPurchaseOrderStatus` | Yes (no unit/freight in response) | Yes |
| Landed cost | `viewLandedCosts` | **No** | Yes |
| Proposal create/get/confirm/cancel | `createDraftPurchaseOrders` | **No** | Yes |

All checks use `Gate::forUser($authenticatedUser)`; callers must pass the real `User` model, not a client-supplied role.

Responses are shaped arrays; PO lookup omits costs; proposals omit bank details; suppliers loaded with `id,name` only where needed.

## Snapshot integrity

- Payload built at **create** from validated DB state (supplier name, product SKU/name, costs, dates).
- Confirm reads **only** stored payload after **hash verification**; no confirmation input overrides lines.
- Eloquent cannot mutate payload; raw SQL tampering fails confirm (tested).

## Idempotency and concurrency

- **Create:** Unique `(user_id, idempotency_key)`; same hash → same proposal; different hash → error; duplicate insert race handled.
- **Confirm:** Transaction + `lockForUpdate()`; already confirmed → same PO id; expired/cancelled → error; integrity failure → no PO.
- **MySQL parallel sessions:** Not verified in automated tests (environment uses SQLite in-memory for isolation). Sequential double-confirm and rollback tests provide partial assurance.

## Test database safety

| Before audit | After audit |
|--------------|-------------|
| `RefreshDatabase` → `migrate:fresh` on first test | **`DatabaseTransactions`** roll back each test |
| Dev seed data at risk on `php artisan test` | Dev data preserved; only test rows rolled back |

Optional: dedicated MySQL `stone_erp_ai_test` in `.env.testing.example` for teams that prefer not to share any connection with dev (requires DBA create + grant).

## Known limitations and recommendations

1. **Concurrency:** Add a dedicated MySQL integration test job with two PDO connections if production requires proof under parallel confirm (not done in Phase 4.3).
2. **Proposal side effects:** `getProposal()` and `cancelProposal()` may mark pending proposals **expired** when TTL elapsed (documented behavior).
3. **MySQL CHECK on stock:** Still enforced in production MySQL; SQLite tests skip CHECK (application still validates non-negative stock).
4. **Update PHASE3-AUTHORIZATION.md** when convenient to reflect SQLite test defaults (audit note only).

## Related docs

- `docs/PHASE4-ERP-SERVICES.md`
- `docs/PHASE4-DRAFT-PO-WORKFLOW.md`
