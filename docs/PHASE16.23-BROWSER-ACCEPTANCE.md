# Phase 16.23 — Supplier-specific delayed PO browser acceptance and regression

**Date:** 2026-11-09  
**Agent:** Cursor Composer 2.5  
**Project:** StoneERP-AI-Assistant  

## Executive summary

**Overall result: PASS WITH FINDINGS**

Phase 16.22 supplier-specific overdue behaviour was verified against the **development** database `stone_erp_ai` using in-process HTTP acceptance (same API the chat UI calls). Two reproducible follow-up/punctuation defects found during acceptance were corrected with minimal patches and regression tests. **Live Ollama was not tested.** **No destructive database operation** was performed. **No Git commit or push** was performed.

---

## Environment and startup

| Item | Detail |
|------|--------|
| OS | Windows 10 (build 26200) |
| Dev database | `stone_erp_ai` on `127.0.0.1:3307` (verified via acceptance script guard and `.env`) |
| Test database | `stone_erp_ai_test` (PHPUnit only — not used in this phase) |
| MySQL | `scripts\mysql-start.cmd` (project-local port **3307**) — **LISTENING** at acceptance time |
| Redis / Memurai | `scripts\memurai-start.cmd` — **not required** for OpenAI synchronous assistant paths used here |
| Laravel | `scripts\dev.cmd php artisan serve --host=127.0.0.1 --port=8000` |
| Vite | Optional for UI dev: `scripts\npm-user.cmd run dev`; pre-built assets in `public/build/` are sufficient for chat |
| Queue worker | `scripts\queue-worker.cmd` — **only when `AI_PROVIDER=ollama`** |
| AI provider (local) | `openai` (`AI_PROVIDER=openai`) |
| Demo users | `sales@example.test`, `purchasing@example.test` (`DEMO_USER_PASSWORD` in `.env`) |

### Recommended startup (interview / manual UI)

```bat
scripts\mysql-start.cmd
scripts\dev.cmd php artisan serve --host=127.0.0.1 --port=8000
```

Optional acceptance helpers (no secrets in JSON output):

```bat
scripts\dev.cmd php tools/phase1621_browser_bootstrap.php
scripts\dev.cmd php tools/phase1623_supplier_overdue_acceptance.php
```

Delete `storage/logs/phase1621-browser-session.json` after use (contains API tokens).

### Browser UI smoke

- Cursor IDE Browser MCP: `http://127.0.0.1:8000/` — login shell loads (**Title:** Stone ERP — Slab Inventory Assistant).
- Full click-through with password was not recorded; chat behaviour was validated via **HTTP acceptance** (identical `/api/assistant` contract).

---

## AI testing strategy

| Path | What was verified |
|------|-------------------|
| **Deterministic preflight** | Purchasing supplier-specific overdue, Sales supplier-specific refusal, PO status/list/stock, landed-cost gates |
| **Live OpenAI** | May run when no deterministic handler matches (e.g. Sales general overdue without supplier) — **not claimed as tool-verified** |
| **Ollama** | **Excluded** — not called, configured, or repaired |

---

## ERP snapshot (dev DB, reference date 2026-11-09)

| Entity | Value |
|--------|--------|
| Brazos supplier | `Demo Supplier — Brazos Marble LLC` (id 242) — **1** overdue ordered PO (#516, ETA 2026-10-05) |
| Gulf supplier | `Demo Supplier — Gulf Stone Imports` (id 239) — **2** overdue (#503, #518) |
| Global overdue (ordered, non-null ETA &lt; today) | **5** POs |
| PO #507 | **received** (Central Texas Granite) |
| PO #511 | **ordered** (Brazos Marble LLC) — not received |

User phrasing **“Brazos Marble LLC”** / **“Gulf Stone Imports”** resolves via `SupplierFilterResolution` (`LIKE` + demo short names).

---

## Issues found and minimal fixes

| # | Reproduction | Root cause | Fix | Regression test |
|---|--------------|------------|-----|-----------------|
| 1 | Purchasing, after overdue thread: *“What is the status of PO-507?”* → *No supplier matched "What is the status of PO-507"* | `expandOverdueSupplierFollowUp` treated full PO status sentence as a supplier name | Skip overdue supplier expansion when follow-up contains a PO id; reject PO-bearing text in `extractNamedEntityFollowUp` | `DeterministicFollowUpResolverTest::test_overdue_thread_leaves_po_status_follow_up_unexpanded` |
| 2 | Purchasing: *“Show me all overdue POs from Gulf Stone Imports.”* (trailing period) | Supplier hint included `.` → no DB match | Trim trailing punctuation in `SupplierFilterResolution::resolveByNameHint` | Covered by `phase1623` acceptance `PURCH-02` |

---

## Purchasing acceptance matrix

Questions tested (deterministic HTTP unless noted):

| # | Question | Result | Notes |
|---|----------|--------|-------|
| 1 | Which purchase orders from Brazos Marble LLC are delayed? | **Pass** | PO #516, 35 days overdue |
| 2 | Show me all overdue POs from Gulf Stone Imports. | **Pass** | POs #503, #518 (after punctuation fix) |
| 3 | Delayed POs for supplier with none (Hill Country Quartz Co) | **Pass** | Clear no-match message |
| 4 | What is the status of PO-507? | **Pass** | received |
| 5 | Show all purchase orders. | **Pass** | 23 POs listed |
| 6 | Show all overdue purchase orders. | **Pass** | 5 overdue rows |

---

## Sales acceptance matrix

| # | Question | Result | Notes |
|---|----------|--------|-------|
| 1 | Which purchase orders from Brazos Marble LLC are delayed? | **Pass** | Exact soft-denial message |
| 2 | Show overdue POs from Gulf Stone Imports. | **Pass** | Soft denial |
| 3 | How many delayed POs does Brazos Marble LLC have? | **Pass** | Soft denial |
| 4 | What is the status of PO-507? | **Pass** | received |
| 5 | Is PO-511 received? | **Pass** | ordered (truthful) |
| 6 | Show me all purchase orders. | **Pass** | Listing |
| 7 | Show me all overdue purchase orders. | **Pass*** | *Non-deterministic OpenAI clarification prompt — not supplier-specific denial* |
| 8 | What is the status of the purchase order from Brazos Marble LLC? | **Pass** | Supplier-scoped PO list (allowed) |

---

## Conversation / filter isolation (Purchasing)

| Step | Message | Result |
|------|---------|--------|
| 1 | Which purchase orders from Brazos Marble LLC are delayed? | Brazos overdue list |
| 2 | What about Gulf Stone Imports? | **Gulf** overdue list (supplier swap) |
| 3 | What is the status of PO-507? | **PO #507 status** (no stale supplier filter; after fix #1) |
| 4 | Show all purchase orders. | Full list (no overdue-only filter retained) |

Sales: after supplier-specific denial, *What is the status of PO-507?* still returns PO status — **Pass**.

---

## Existing feature regression (sampled)

| Area | Check | Result |
|------|--------|--------|
| Stock (Sales) | Houston Calacatta 42 slabs | **Pass** (`REG-SALES-stock`) |
| Landed cost | Sales 403 / Purchasing 200 on PO #507 | **Pass** |
| Sales PO creation refusal | `phase1621_sales_po_smoke.php` | **Pass** (re-run this phase) |
| Procurement / warehouse / proposals | Not re-run in 16.23 (unchanged since 16.21) | — |

---

## Automated tests

| Suite | Result |
|-------|--------|
| `tools/phase1623_supplier_overdue_acceptance.php` | **20/20 checks Passed** → `tools/phase1623_supplier_overdue_acceptance.json` |
| `SupplierOverduePurchaseOrderPhase16_22Test` | **7 tests, 20 assertions** — OK |
| `DeterministicFollowUpResolverTest` | **7 tests** — OK (includes new PO follow-up case) |
| **Full PHPUnit** | **OK (436 tests, 1351 assertions)** |

---

## Features not verified this phase

- Live Ollama / async queue worker E2E  
- Live OpenAI tool-calling for every Sales/Purchasing edge question  
- Exhaustive browser click-through (login password not recorded in docs)  
- Full procurement proposal sidebar clicks (16.21 coverage still applies)  
- Claude provider runtime  

---

## Files touched in 16.23

| File | Role |
|------|------|
| `docs/PHASE16.23-BROWSER-ACCEPTANCE.md` | This document |
| `tools/phase1623_supplier_overdue_acceptance.php` | HTTP acceptance harness |
| `tools/phase1623_supplier_overdue_acceptance.json` | Run artifact |
| `app/Services/AI/Context/DeterministicFollowUpResolver.php` | Follow-up fix |
| `app/Services/Erp/SupplierFilterResolution.php` | Punctuation trim |
| `tests/Feature/AI/Context/DeterministicFollowUpResolverTest.php` | Regression test |

---

## Confirmations

- **Development database** `stone_erp_ai` used; **not reset**; no `migrate:fresh` / `db:wipe`  
- **Ollama** not modified or tested  
- **No Git commit or push**
