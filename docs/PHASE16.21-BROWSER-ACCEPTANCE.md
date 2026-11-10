# Phase 16.21 — Browser acceptance and interview demonstration readiness

**Date:** 2026-11-09  
**Agent:** Cursor Composer 2.5  
**Project:** StoneERP-AI-Assistant  

## Executive summary

**Overall result: PASS WITH FINDINGS**

The chat UI and deterministic ERP workflows are usable for interview demonstration. One application bug (Sales PO refusal vs. clarification ordering) was fixed with a regression test. The development database must include migration `2026_11_09_180000_create_pending_procurement_clarifications_table` before procurement chat flows work. **Live Ollama was not exercised** (per phase constraints). **The development database was not reset.** **No Git commit or push** was performed in this phase.

---

## Environment and startup

| Item | Detail |
|------|--------|
| OS | Windows 10 (build 26200) |
| App URL | `http://127.0.0.1:8000` |
| Laravel | Served via `scripts\dev.ps1 php artisan serve --host=127.0.0.1 --port=8000` |
| MySQL | `stone_erp_ai` on `127.0.0.1:3307` (per `.env`) |
| AI provider (local) | `openai` (deterministic paths avoid live calls where possible) |
| Frontend assets | Pre-built `public/build/manifest.json` ( `npm run build` failed on this Node/tooling; existing build loads correctly) |
| Redis / queue | Not required for OpenAI synchronous assistant paths used in this audit |
| Demo users | `sales@example.test`, `purchasing@example.test` (password from `DEMO_USER_PASSWORD` in `.env` — not documented here) |

### Recommended demo startup (from README)

```bat
scripts\dev.cmd php artisan serve
```

Ensure migrations are applied (`php artisan migrate`) so `pending_procurement_clarifications` exists. Optional acceptance helpers:

```bat
scripts\dev.cmd php tools/phase1621_browser_bootstrap.php
scripts\dev.cmd php tools/phase1621_chat_ui_acceptance.php
```

Delete `storage/logs/phase1621-browser-session.json` after bootstrap (contains API tokens).

### Browser tooling

- Cursor IDE Browser MCP (Chromium) used for UI smoke checks.
- No critical console errors observed on login shell and chat send paths tested.

---

## Ollama exclusion

- Ollama connectivity was **not** tested, repaired, or reconfigured.
- Async Ollama queue worker behaviour was **not** verified in the browser.
- Automated tests continue to mock/fake providers where applicable.

---

## Issue found and fix

| Issue | Reproduction | Root cause | Fix | Regression test |
|-------|--------------|------------|-----|-----------------|
| Sales draft-PO requests sometimes received product/clarification text instead of role refusal | Sales user: `Create a draft PO for 5 slabs of Calacatta from Demo Supplier — Gulf Stone Imports` after context resolution / ambiguous product catalog | `SalesPurchaseOrderCreationRefusal` ran **after** follow-up clarification branch | Early deterministic refusal in `AssistantRequestService`; include refusal in `ErpDeterministicPreflight::shouldBypassClarification` | `SalesPurchaseOrderCreationRefusalTest::test_sales_po_refusal_precedes_ambiguous_calacatta_clarification` |

---

## HTTP acceptance artifacts

| Artifact | Purpose | Result (final run) |
|----------|---------|-------------------|
| `tools/phase1621_chat_ui_acceptance.json` | Chat API procurement A–G + sales checks | **All checks Passed** (2026-11-09T17:28+ batch) |
| `tools/phase162_acceptance_api.json` | Auth, sales/purchasing REST, partial proposal API | AUTH/SALES/PURCH largely **Passed**; some PROP-* cases depend on script payload shape (see limitations) |
| `tools/phase1621_sales_po_smoke.php` | Isolated sales PO refusal | **Passed** |

---

## Sales acceptance matrix

| # | Check | Browser | API / script | Notes |
|---|--------|---------|--------------|-------|
| 1 | Login | Login form loads; session chat UI reachable | `AUTH-*` Passed | Form login not re-tested with password in browser (token bootstrap used once) |
| 2 | Chat UI | Loads; send/stock reply | — | |
| 3 | Product / stock search | Houston Calacatta stock (42 slabs) | `UI-SALES-stock` Passed | Deterministic |
| 4 | PO search / status | — | `SALES-02*` Passed (phase162 API) | |
| 5 | Overdue PO | — | Covered by PHPUnit / phase162 | |
| 6 | Landed cost denied | — | `SALES-05/06` 403 | |
| 7 | Supplier banking hidden | — | PHPUnit + phase162 | |
| 8 | Draft PO refused | New chat (after fix) | `UI-SALES-po-refusal` Passed | **Fixed** in 16.21 |
| 9 | Chat history | Conversation id displayed | — | |
| 10 | Logout | Control present | `AUTH-06` 204 | Full browser logout click not recorded |

---

## Purchasing acceptance matrix

| # | Check | Browser | API / script | Notes |
|---|--------|---------|--------------|-------|
| 1 | Login | — | `PURCH-01` Passed | |
| 2 | Product search | — | Deterministic / ERP tests | Live OpenAI search not required for demo PO path |
| 3 | Supplier search | — | `PURCH-*` | |
| 4 | Warehouse search | — | Clarification lists DB warehouses | |
| 5 | Stock | — | `PURCH-06` | |
| 6 | PO / overdue queries | — | phase162 + PHPUnit | |
| 7 | Landed cost | — | `PURCH-04/05` | |
| 8 | Purchasing-only data | — | Passed | |
| 9 | Draft PO proposal | — | `UI-B-proposal` Passed | |
| 10 | Proposal sidebar | Partial | Confirm/cancel via API in script | Full sidebar click-through not exhaustively browser-recorded |

---

## Procurement and warehouse workflow matrix

| Scenario | HTTP script | PHPUnit |
|----------|-------------|---------|
| A — Missing destination | `UI-A-clarification`, `UI-A-no-po` Passed | `ProcurementWarehouseClarificationTest` |
| B — Houston resume | `UI-B-proposal` Passed | Same |
| C — Qty + warehouse change | `UI-C-qty-warehouse` Passed | Same |
| D — Multi-destination lines | — | Covered in feature tests (if enabled in product) |
| E — Invalid warehouse | `UI-E-invalid-wh` Passed | Same |
| F — Confirm PO + line WH | `UI-F-confirm` Passed | Idempotency tests |
| F — Stock unchanged | `UI-F-stock-unchanged` Passed | Business rules tests |
| G — Cancel proposal | `UI-G-cancel` Passed | Same |

---

## Proposal sidebar and frontend

- Proposal payload returned on chat turn includes supplier, lines, quantities, costs, warehouse id (verified via API script).
- Confirm/cancel endpoints exercised with Sanctum + conversation key.
- Loading/disabled states on send button observed during in-flight chat.
- **Not verified:** duplicate-click on confirm in browser, full sidebar field-by-field visual audit, page refresh with open proposal.

---

## Security verification

| Check | Result |
|-------|--------|
| Sales cannot access purchasing proposal create | `SEC-02` 403 |
| Unauthenticated API | `SEC-01` 401 |
| Sales PO creation refusal | Deterministic + test |
| Conversation ownership / pending clarification scoping | PHPUnit `ProcurementWarehouseClarificationTest` |
| Expired proposals / idempotent confirm | PHPUnit proposal suite |
| CSRF / Sanctum | SPA uses bearer token; API tests use Sanctum |

---

## Automated tests

| Run | Result |
|-----|--------|
| Full suite after fix | **OK (427 tests, 1328 assertions)** |
| Prior baseline (16.20) | 426 tests, 1325 assertions |

---

## Features not verified (limitations)

- Live **Ollama** assistant (202/async polling).
- **npm run build** on current Node (Vite `node:fs/promises` export error); demo uses existing build.
- End-to-end **Purchasing** chat + proposal sidebar purely via browser clicks (API script provides equivalent coverage).
- **Live OpenAI** tool-calling for non-deterministic questions.
- **phase162_acceptance_api** `PROP-*` failures where script omits `warehouse_id` on proposal lines (tooling gap, not product regression).
- **Scenario D** multi-warehouse lines in browser only.

---

## Development database

- **Not reset.**
- Initial audit hit missing `pending_procurement_clarifications` (HTTP 500); table later confirmed present via `php artisan db:table pending_procurement_clarifications`.
- Interview checklist: run `php artisan migrate` on dev DB when schema errors appear.

---

## Files modified (application)

- `app/Services/AI/AssistantRequestService.php`
- `app/Services/AI/Grounding/ErpDeterministicPreflight.php`
- `tests/Feature/AI/Grounding/SalesPurchaseOrderCreationRefusalTest.php`

## Files added / updated (tooling & docs)

- `tools/phase1621_browser_bootstrap.php`
- `tools/phase1621_chat_ui_acceptance.php`
- `tools/phase1621_sales_po_smoke.php`
- `docs/PHASE16.21-BROWSER-ACCEPTANCE.md`

---

## Confirmations

- Ollama was **not** modified or live-tested.
- Development database was **not** reset.
- No Git **commit** or **push** in this phase.
- Secrets were **not** written into this document.
