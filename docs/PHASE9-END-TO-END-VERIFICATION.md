# Phase 9 — End-to-end verification and reliability hardening

This document records workflow verification, manual API procedures, migrations, idempotency design, provider checks, and automated test evidence for the authenticated assistant (`POST /api/assistant`) and related ERP APIs.

## 1. End-to-end workflow (implementation audit)

Verified against existing code paths (read-only review):

1. **Sanctum:** Protected routes use Bearer tokens (`AssistantController` returns **401** when no user).
2. **`POST /api/assistant`:** `AssistantChatRequest` validates `message` and optional `conversation_id`.
3. **Conversation:** `AssistantRequestService` resolves an existing conversation or creates one when `conversation_id` is omitted (`ConversationService`).
4. **Ownership:** Unknown UUID → **404** `conversation_not_found`; another user’s UUID → **403** `conversation_forbidden` (`ConversationAccessException`).
5. **Follow-ups:** `FollowUpQuestionResolver` + `mergeUserMessageResolution` attach standalone resolved text to the user row for context (`ConversationPhase7Test` / `AssistantApiTest` cover Houston → Dallas).
6. **Role tools:** `ToolRegistry::definitionsFor($user)`; Sales omit cost tools; Purchasing includes landed cost and draft proposal tool (`ToolLayerTest`, `AiToolCallingServiceTest`).
7. **ERP services:** Tools delegate to existing Phase 4 services (`checkStock`, `searchPurchaseOrders`, `findOverduePurchaseOrders`, `getLandedCost`, `proposeDraftPurchaseOrder`).
8. **Persistence:** User/assistant/tool rows under conversation; tool traces in `conversation_tool_calls` with ordering.
9. **Grounded answers:** Automated tests fake provider responses that drive real tool calls against seeded DB rows (e.g. Calacatta 3 cm Houston/Dallas).
10. **Draft PO proposal:** Purchasing-only `proposeDraftPurchaseOrder` creates a **pending** proposal record only (`AssistantApiTest` / proposal tests).
11. **Real PO:** Only **`POST /api/purchase-order-proposals/{proposalId}/confirm`** creates `purchase_orders` + lines (Phase 5.2).

Architecture was not rewritten for style-only reasons; Phase 9 additions are targeted (idempotency + docs + tests).

## 2. Migrations

### `2026_11_06_210000_update_conversation_tool_calls_unique_key.php`

- **Change:** Drops unique index `conv_tool_calls_conv_provider_uc` on `(conversation_id, provider_tool_call_id)` and adds `conv_tool_calls_conv_user_msg_provider_uc` on **`(conversation_id, user_message_id, provider_tool_call_id)`**.
- **Data preservation:** Non-destructive index change only; existing rows remain; uniqueness is stricter per user turn (fixes duplicate `provider_tool_call_id` across turns).
- **Rollback:** Restores the older two-column unique index.

### `2026_11_06_220000_add_client_idempotency_to_conversation_messages.php`

- **Change:** Adds nullable `client_idempotency_key` (max 128), nullable JSON `idempotency_response`, and unique index **`conv_msgs_conv_client_idem_uc`** on `(conversation_id, client_idempotency_key)` (null key values are not subject to uniqueness in MySQL — clients must send a key only when replay is intended).
- **Rollback:** Drops index and columns.

### Database names and safety

| Environment | Typical `DB_DATABASE` |
|---------------|------------------------|
| Local / dev (`.env.example`) | `stone_erp_ai` |
| PHPUnit (`phpunit.xml`) | `stone_erp_ai_test` |

- **`tests/TestCase.php`** refuses to run unless the active connection database is exactly **`stone_erp_ai_test`**, so the suite cannot accidentally run against the development database.
- **No automated command** in this repo targets `migrate:fresh` at `stone_erp_ai`; destructive commands are manual. **Never** run `migrate:fresh` against `stone_erp_ai` (wipes dev data). For a clean test DB only: use **`--env=testing`** so `DB_DATABASE` stays `stone_erp_ai_test` (see Phase 7 doc).

**Applying to development (`stone_erp_ai`):** run a normal migrate (additive migrations only). This phase did **not** run destructive migrations against the dev database from automation.

## 3. Manual API verification (Sales and Purchasing)

Use **`https://your-host`** or `http://localhost` as appropriate. Replace tokens and UUIDs from responses.

### Shared: obtain a token

**Demo users** (after seeding — see `docs/PHASE3-SEEDERS.md`):

| Email | Role |
|-------|------|
| `sales@example.test` | sales |
| `purchasing@example.test` | purchasing |

```http
POST /api/auth/token
Content-Type: application/json

{
  "email": "sales@example.test",
  "password": "<DEMO_USER_PASSWORD from .env>",
  "device_name": "phase9-manual"
}
```

Use the returned `token` as:

```http
Authorization: Bearer <token>
Content-Type: application/json
Accept: application/json
```

### Sales user

1. **Authenticate** (above).
2. **First question** (new conversation):

```http
POST /api/assistant
Authorization: Bearer <sales token>
Content-Type: application/json

{
  "message": "How many 3cm Calacatta slabs do we have in Houston?"
}
```

- Expect **200** with `data.conversation_id` set.
- Seeded expectation (see `docs/PHASE3-SEEDERS.md`): product **DEMO-STQ-CAL-30** — **42 slabs in Houston**.

3. **Follow-up:**

```http
POST /api/assistant
Authorization: Bearer <sales token>
Content-Type: application/json

{
  "conversation_id": "<uuid from step 2>",
  "message": "And in Dallas?"
}
```

- Expect **200**; seeded **18 slabs in Dallas**.

4. **Landed cost (denied):**

```http
POST /api/assistant
Authorization: Bearer <sales token>
Content-Type: application/json

{
  "conversation_id": "<same uuid>",
  "message": "What is the landed cost for purchase order 1?"
}
```

- Expect assistant answer that reflects **permission denial** (Sales cannot use `getLandedCost`); automated tests assert the tool is blocked.

5. **Supplier bank details (no disclosure):**

```http
POST /api/assistant
Authorization: Bearer <sales token>
Content-Type: application/json

{
  "conversation_id": "<same uuid>",
  "message": "What are the bank account details for our suppliers?"
}
```

- Expect **no** raw bank numbers; tool/API layers do not expose encrypted supplier bank fields to Sales.

### Purchasing user

Repeat auth with `purchasing@example.test`.

1. **Stock:** e.g. `"message": "How much Calacatta 3cm stock is in Houston?"` — expect seeded **42** (same product as Sales).
2. **Landed cost:** reference an existing PO id from seeded data, e.g. `"What is the landed cost for purchase order <id>?"` — Purchasing path may call `getLandedCost` (see seed PO ids in DB or `GET /api/purchase-orders`).
3. **Overdue POs:** `"Which purchase orders are overdue?"` — tool uses `findOverduePurchaseOrders`.
4. **Draft PO proposal:** Ask to draft a PO for a seeded supplier/product (natural language). Expect a **proposal preview** in the assistant reply and a **pending** proposal in the system (no real PO yet). The exact assistant wording varies with the model; confirm via **`GET /api/purchase-order-proposals/{id}?conversation_key=...`** using the `conversation_key` from the assistant context (Phase 5 — same contract as proposal API).
5. **No PO until confirm:** `GET /api/purchase-orders` should not show a new draft PO until confirmation (or match existing seeded POs only).
6. **Confirm:** `POST /api/purchase-order-proposals/{proposalId}/confirm?conversation_key=...` with proposal `Idempotency-Key` as documented in **`docs/PHASE5-API.md`** — creates exactly one draft PO and lines.
7. **Idempotent confirm:** Repeat the **same** confirm request with the **same** `Idempotency-Key` — expect **200** with the **same** `purchase_order_id` (no second PO).

### Optional: assistant HTTP idempotency (retries)

For client retries on **existing** conversations:

```http
POST /api/assistant
Authorization: Bearer <token>
Idempotency-Key: turn-client-001
Content-Type: application/json

{
  "conversation_id": "<uuid>",
  "message": "How many 3cm Calacatta slabs in Houston?"
}
```

Repeat with the **same** header and body → identical JSON. Same key, **different** `message` → **409** `idempotency_body_mismatch`. While the first request is still working → **503** `idempotency_in_progress` (retry with backoff).

## 4. Assistant idempotency — design and limitations

**Implemented (Phase 9):**

- Optional header **`Idempotency-Key`** (trimmed non-empty string).
- Requires **`conversation_id`** (422 if missing).
- **Scope:** Per **authenticated user** (cache lock key includes `user_id`) and **conversation** (DB unique `(conversation_id, client_idempotency_key)` on user messages).
- **Flow:** `Cache::lock('assistant:idem:{userId}:{key}', …)` for the whole turn; transaction loads or creates the user row with `client_idempotency_key`; on success the full `AssistantRequestResult` is stored as JSON on that row for **replay**.
- **409** if the key was reused with a different message body.
- **503** if the row exists but `idempotency_response` is not yet populated (in-flight or failed mid-write).

**Protects:**

- Duplicate **user message rows** on retries.
- Duplicate **tool execution** and duplicate **AI work** on successful completion (replay returns cached outcome).
- Duplicate **draft proposals** for the same idempotent turn (same assistant outcome, including proposal tool result).

**Does not change:**

- Existing **proposal confirmation** idempotency on `POST /api/purchase-order-proposals/{id}/confirm` (Phase 5).

**Limitations:**

- **New conversations:** omitting `conversation_id` cannot use this header (422) — first message creates the thread; subsequent turns can use `Idempotency-Key`.
- **Cache driver:** `Cache::lock` requires a **supported cache store** in production (file/array drivers are poor for multi-server locking). Misconfiguration weakens concurrency guarantees.
- **Concurrent DB sessions:** Automated tests do not prove MySQL-wide serialisation under parallel connections; **503** and lock behaviour should be validated under real multi-instance load if needed.
- **Failure before persist:** If the process dies before `idempotency_response` is saved, the client may see **503** and must retry the same key + body (safe).

## 5. Provider verification (no live calls in CI)

**Central selection:** `config('ai.provider')` via `AiProviderFactory` / `AiServiceProvider` — OpenAI and Claude implement **`AiProviderInterface`**.

**Review checklist (code + config):**

- API keys read from env only; missing key → **`AiProviderException::CODE_MISSING_API_KEY`** mapped to **502** (`ai_authentication_failed`), not echoed to clients.
- Errors mapped in `OpenAiProvider` / `ClaudeProvider` without logging raw credentials.
- **`AiToolCallingService`:** records usage and timing; incomplete tool runs surface `completed: false` / `assistant_response_incomplete` where applicable (see Phase 8 status table).
- Summaries instruct the model not to include secrets (`ConversationSummaryService`).

### Manual checks when credentials exist (human-operated)

**OpenAI**

1. Set `AI_PROVIDER=openai`, `OPENAI_API_KEY`, optional `OPENAI_ORGANIZATION` / `OPENAI_PROJECT` in `.env` (not committed).
2. Run one **`POST /api/assistant`** with a simple stock question; confirm **200**, plausible answer, `data.provider` = `openai`, `usage` populated.
3. Temporarily set an invalid key → expect **502** `ai_authentication_failed`, no key in JSON body.

**Claude**

1. Set `AI_PROVIDER=claude`, `ANTHROPIC_API_KEY`, optional model/base URL.
2. Repeat a short assistant question; confirm **200** and `data.provider` = `claude`.
3. Invalid key → safe **502** / mapped error without secret leakage.

Do **not** commit keys or paste them into logs/tickets.

## 6. Security and data integrity (Phase 9 review)

| Area | Status |
|------|--------|
| Sales / Purchasing authorization | Gates + `ToolRegistry`; API resources strip sensitive fields (`docs/PHASE3-AUTHORIZATION.md`, `PHASE5-API.md`). |
| Conversation ownership | 403/404 as above; inactive conversation → 403. |
| Tool argument validation | Schema validation + forbidden keys (`ToolLayerTest`). |
| Tool result redaction | `ToolResultRedactor` keys such as `password`, `api_key`. |
| Supplier bank encryption | Model casts / Phase 3 seeder notes. |
| Draft proposal integrity | Trusted `conversation_key` / `turn_idempotency_key` from server recording (Phase 7). |
| Confirmation transaction | Phase 5 service + tests (`PurchaseOrderProposalApiTest`). |
| AI error handling | Mapped HTTP + stable `error.code` (`PHASE8-ASSISTANT-API.md`). |
| Rate limiting | `api-assistant` per user (`AI_ASSISTANT_RATE_LIMIT`). |
| Logs / responses | No provider keys in assistant JSON; tests use fake keys only. |

No additional code changes were required beyond idempotency and tests for verified gaps in this pass.

## 7. Automated tests executed (Phase 9)

Commands (from repo root):

```powershell
composer validate --no-check-publish
.\scripts\dev.cmd php artisan test
.\scripts\dev.cmd php artisan route:list --path=assistant
```

**Results (this workspace run):**

- `composer.json` is **valid**.
- Routes: **`POST api/assistant`** → `Api\AssistantController@store`.
- PHPUnit: **176 passed (535 assertions)** — `APP_ENV=testing`, **`DB_DATABASE=stone_erp_ai_test`** per `phpunit.xml`; `TestCase` blocks non-test DB names.

**New / extended coverage:** `tests/Feature/Api/AssistantApiTest.php` — `Idempotency-Key` without `conversation_id` (422), replay (200 + identical payload + single tool call row), body mismatch (409).

**Not claimed:** MySQL concurrency across separate sessions (no dedicated stress test).

## 8. Outstanding risks and known limitations

- First assistant message in a thread cannot use `Idempotency-Key` until `conversation_id` exists (by design).
- Idempotency locking depends on **cache store** quality in production.
- Manual purchasing flows that rely on exact natural-language tool JSON may need iteration; verify proposals via Phase 5 GET endpoint.
- Git metadata was unavailable in the automation environment (`git` not a repo here); use your local `git status` for file lists.

## 9. Related documentation

- `docs/PHASE8-ASSISTANT-API.md` — endpoint contract (updated for `Idempotency-Key`).
- `docs/PHASE5-API.md` — auth, ERP reads, proposal confirm idempotency.
- `docs/PHASE7-CONVERSATIONS-AND-CONTEXT.md` — context and follow-ups.
- `docs/PHASE3-SEEDERS.md` — Calacatta Houston/Dallas counts and demo users.
