# Phase 10.2 — Local runtime and end-to-end acceptance

This document records how to run the stack locally, what was verified automatically in Phase 10.2, and what you must still validate in the browser. It does **not** contain secrets; keep API keys and passwords only in your private `.env`.

## 1. Runtime environment

| Component | Expected (from `config/` and `.env.example`) |
|-----------|-----------------------------------------------|
| Laravel | 12.x, `scripts\dev.cmd php …` |
| MySQL | `127.0.0.1:3307`, database `stone_erp_ai` (dev) |
| Redis-compatible | Memurai at `127.0.0.1:6379` (`REDIS_CLIENT=predis`) |
| Queue | `QUEUE_CONNECTION=redis` (`.env.example`) |
| AI | `AI_PROVIDER=openai` / `OPENAI_API_KEY` in `.env` (server-side only) |

**Startup scripts (match repo):**

| Step | Command |
|------|---------|
| MySQL | `.\scripts\mysql-start.cmd` |
| Memurai | `.\scripts\memurai-start.cmd` |
| Laravel | `.\scripts\dev.cmd php artisan serve` |
| Vite (HMR) | `scripts\npm-user.cmd run dev` |
| Production assets already built | `public\build\manifest.json` from `npm run build` |

`docs/PHASE10-FRONTEND-CHAT.md` is the detailed frontend/toolchain guide (portable Node, `npm-user.cmd`).

Routes: `GET /` → chat UI (`routes/web.php`). API under `routes/api.php` (`/api/auth/token`, `/api/user`, `/api/assistant`, proposal routes).

## 2. Configuration presence (no secret values)

Your `.env` should define at least (names only):

- `DB_*` — MySQL connection to `stone_erp_ai` on port `3307` (per `.env.example`)
- `REDIS_*` — host `127.0.0.1`, port `6379`, `REDIS_CLIENT=predis`
- `AI_PROVIDER`, `OPENAI_API_KEY`
- `DEMO_USER_PASSWORD` — required **only** if you run `DemoUserSeeder`

Never commit `.env`. If a key may have been exposed in a log or screenshot, rotate it at the provider.

## 3. Service verification (automated run, Phase 10.2)

The following was checked with a **temporary** PHP bootstrap script (since removed) using the same `.env` as `php artisan`:

| Check | Result |
|-------|--------|
| MySQL PDO (`127.0.0.1:3307`, DB `stone_erp_ai`) | Connected (`mysql_ok=yes`) |
| Redis/Memurai PING | Success (`redis_ping` non-error; predis returns `true` serialized as `{}` in some displays) |
| `QUEUE_CONNECTION` | `redis` |

**Note:** No queue worker was left running; no jobs were dispatched. Redis connectivity is sufficient to confirm Memurai reachability for the queue driver.

## 4. Migrations

`php artisan migrate:status` (dev DB) showed **all** application migrations **Ran**, including:

- ERP tables (`products`, `warehouses`, `suppliers`, `stock`, `purchase_orders`, …)
- Conversation / assistant / idempotency migrations (`2026_11_06_210000…`, `2026_11_06_220000…`)

**Do not** run `migrate:fresh` against `stone_erp_ai` in normal workflows.

## 5. Seed data verification

### Phase 10.2 (initial automation snapshot)

On the **development** database used during the first Phase 10.2 run, ERP row counts were **0** before `db:seed`. Demo users for `sales@example.test` and `purchasing@example.test` could still exist from an earlier seed.

### Phase 10.3 (development DB populated)

**Phase 10.3** ran `.\scripts\dev.cmd php artisan db:seed --no-interaction` against **`stone_erp_ai`** (not the test DB). Verified counts after seed (and unchanged on a second run):

| Check | Value |
|-------|------:|
| Products | 22 |
| Warehouses | 3 |
| Suppliers | 5 |
| Stock rows | 12 |
| Purchase orders | 16 |
| PO lines | 22 |
| Overdue (ordered + ETA before today) | 5 |
| Houston Calacatta 3 cm (`DEMO-STQ-CAL-30`) | 42 slabs |
| Dallas Calacatta 3 cm | 18 slabs |
| `sales@example.test` role | sales |
| `purchasing@example.test` role | purchasing |

**Expected** (reference — see `docs/PHASE3-SEEDERS.md`):

| Entity | Expected count |
|--------|----------------|
| Products | 22 |
| Warehouses | 3 |
| Suppliers | 5 |
| Purchase orders | 16 |
| PO lines | 22 |
| Calacatta 3 cm (DEMO-STQ-CAL-30) Houston stock | 42 slabs |
| Calacatta 3 cm Dallas stock | 18 slabs |

**To load ERP demo data (non-destructive, idempotent seeders):**

```powershell
.\scripts\dev.cmd php artisan db:seed --no-interaction
```

**Demo passwords** require `DEMO_USER_PASSWORD` in `.env`; then:

```powershell
.\scripts\dev.cmd php artisan db:seed --class=Database\Seeders\DemoUserSeeder --no-interaction
```

Re-run the count checks locally (e.g. MySQL client or `php artisan tinker`) after seeding — do not use `migrate:fresh` on dev unless you intentionally wipe data.

## 6. OpenAI configuration (no live call in Phase 10.2)

- `config/ai.php` — `provider` from `AI_PROVIDER`, OpenAI block from `OPENAI_*` env vars.
- `config/openai.php` — `api_key` from `OPENAI_API_KEY`.
- **Manual paid test** (when you choose): `.\scripts\dev.cmd php artisan ai:verify-connection` — sends one short prompt via `AiAgentService` (see `app/Console/Commands/AiVerifyConnectionCommand.php`).

Phase 10.2 did **not** run `ai:verify-connection` to avoid unintended billed usage.

## 7. Frontend ↔ API alignment

Verified by code review (no browser automation in Phase 10.2):

| Area | Backend contract | Frontend (`resources/js/chat/`) |
|------|------------------|----------------------------------|
| Login | `POST /api/auth/token` → `token`, `user` | Uses `token`; loads profile via `GET /api/user` |
| Current user | `GET /api/user` → wrapped `data` | Unwraps `me.data.data` when present |
| Logout | `DELETE /api/auth/token` | Revokes then clears sessionStorage |
| Assistant | `POST /api/assistant` | `message`, optional `conversation_id`; `Idempotency-Key` when continuing |
| Proposal (Purchasing) | GET/POST/DELETE + `conversation_key` query | Uses conversation **public UUID** as `conversation_key` |

**Intentional limitation:** Assistant JSON does not expose structured proposal objects; Purchasing loads **GET `/api/purchase-order-proposals/{id}`** after the user enters or pastes a proposal id (optional heuristic from assistant text).

**Layout:** Desktop shell uses ~**75%** width/height (`w-[min(92vw,1280px)]`, `h-[min(76vh,820px)]`); transcript scrolls in `#chat-scroll`; composer fixed in footer.

## 8. Automated test results

| When | Command | Result |
|------|---------|--------|
| Phase 10.2 | `npm run test`, `npm run build`, `composer validate`, `php artisan test` | Pass; PHPUnit **176 passed (535 assertions)** |
| Phase 10.4 (latest agent run) | Same suite | Pass; PHPUnit **176 passed (535 assertions)**; `composer.json` valid |

Always use **`stone_erp_ai_test`** for PHPUnit (see `phpunit.xml`). **Never** run `migrate:fresh` against `stone_erp_ai` for routine work.

## 9. Manual acceptance checklist (browser — you perform)

Prerequisites: MySQL + Memurai running, Laravel + Vite (or built assets), ERP seed + demo users as needed, `OPENAI_API_KEY` set for live assistant tests.

### Sales (`sales@example.test`)

- [ ] Log in on `/`
- [ ] Ask: *How many 3cm Calacatta slabs do we have in Houston?* (expect seeded **42**)
- [ ] Follow-up: *And in Dallas?* (expect **18**)
- [ ] Ask for landed cost → expect denial / no cost data in UI
- [ ] Ask for supplier bank details → expect no disclosure

### Purchasing (`purchasing@example.test`)

- [ ] Log in
- [ ] Landed cost question (valid PO id from seeded data)
- [ ] Overdue POs question
- [ ] Draft PO via natural language → pending proposal (no PO until confirm)
- [ ] Load proposal in sidebar → preview
- [ ] **Confirm** explicitly → one draft PO + lines
- [ ] Repeat confirm with same idempotency semantics → idempotent (no duplicate PO)

**These steps require a human in the browser** unless you attach transcripts, screenshots, or logs for an agent review.

## 10. Live OpenAI verification status

- **Command:** `.\scripts\dev.cmd php artisan ai:verify-connection` (one short prompt via `AiAgentService`; may incur API usage).
- **Phase 10.2 / 10.4 (this workspace):** no live OpenAI call was executed in automation (avoid unintended charges).

### Phase 10.4 — user-supplied live provider results

| Item | Result |
|------|--------|
| `ai:verify-connection` (OpenAI) | **Not provided** — paste command output (redact any internal ids if desired; do not paste API keys) |
| Other provider notes | **Not provided** |

## 10a. Phase 10.4 — user-supplied browser acceptance

**No evidence was attached in the Phase 10.4 request.** When you have results, add rows below (or link to a private doc). Use **Pass / Fail / Not run** only for what you actually did.

| Scenario | Result | Notes |
|----------|--------|-------|
| Sales login + role badge | Not reviewed | |
| Houston 3 cm Calacatta stock (42) | Not reviewed | |
| Dallas follow-up (18) | Not reviewed | |
| Sales landed cost denied | Not reviewed | |
| Sales supplier bank details denied | Not reviewed | |
| Purchasing login + role | Not reviewed | |
| Landed cost (Purchasing) | Not reviewed | |
| Overdue POs | Not reviewed | |
| Draft PO proposal preview | Not reviewed | |
| Explicit confirm (one PO) | Not reviewed | |
| Repeat confirm (idempotent) | Not reviewed | |
| Chat layout / responsive | Not reviewed | |

## 11. Outstanding limitations

- **Live provider and browser acceptance** for Phase 10.4 are **unverified** until you supply evidence.
- Proposal flows in the UI depend on GET/confirm APIs and (for Purchasing) loading proposal id from assistant text or manual entry.

## 12. Exact commands to run locally

```powershell
cd D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant
.\scripts\mysql-start.cmd
.\scripts\memurai-start.cmd
.\scripts\dev.cmd php artisan db:seed --no-interaction
# If demo users needed and DEMO_USER_PASSWORD is set:
# .\scripts\dev.cmd php artisan db:seed --class=Database\Seeders\DemoUserSeeder --no-interaction

.\scripts\dev.cmd php artisan serve
```

Second terminal:

```powershell
cd D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant
scripts\npm-user.cmd run dev
```

Open the URL from `artisan serve` (e.g. `http://127.0.0.1:8000`).

## Related docs

- `docs/PHASE10-FRONTEND-CHAT.md`
- `docs/PHASE9-END-TO-END-VERIFICATION.md`
- `docs/PHASE8-ASSISTANT-API.md`
- `docs/PHASE5-API.md`
- `docs/PHASE3-SEEDERS.md`
