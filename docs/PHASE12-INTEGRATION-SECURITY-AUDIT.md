# Phase 12 — Full integration, security, and regression audit

This document records a **static and automated-test** review of Stone ERP AI (Laravel 12, Sanctum, ERP services, AI tools, assistant API, chat frontend, Docker Compose). **No live OpenAI or Claude calls** were made in this audit. **Docker was not executed** on the development machine (Phase 11 limitation remains).

**Sources reviewed:** all `docs/PHASE*.md`, `routes/api.php`, `routes/web.php`, `app/Services/**` (ERP + AI), `app/Http/Controllers/Api/**`, `app/Http/Requests/**`, `app/Services/AI/Tools/**`, `docker-compose.yml`, `Dockerfile`, `config/ai.php`, `config/queue.php`, `phpunit.xml`, and representative tests under `tests/Feature/**`.

---

## 1. Requirements traceability matrix

Status legend: **Pass** = behavior covered by code review + tests where noted; **Partial** = implemented with documented gaps; **N/A** = out of scope for codebase.

| Requirement | Implementation | Test coverage | Status | Gap |
|-------------|----------------|---------------|--------|-----|
| Laravel + PHP (`composer.json` ^8.3) | Laravel 12 app | Env / bootstrap tests | **Pass** | None |
| MySQL 8 database | `config/database.php`, migrations | `UsesErpDatabaseTransactions`, seeders, feature tests | **Pass** | None |
| Redis-compatible queue | `QUEUE_CONNECTION=redis`, Predis, `config/queue.php` | PHPUnit uses `sync` for speed; Docker queue service documented | **Partial** | No integration test proving Redis-backed worker end-to-end |
| Docker Compose (app, MySQL, Redis, queue) | `docker-compose.yml`, `Dockerfile`, `docs/PHASE11-DOCKER.md` | Static YAML review only | **Partial** | Runtime not verified (no Docker here) |
| Queue worker | `queue:work redis` in Compose; local `composer.json` `dev` uses `queue:listen` | Worker process not exercised in PHPUnit | **Partial** | No jobs dispatched from assistant path (see §8) |
| Six core ERP tables | Documented in `docs/PHASE3-ERP-SCHEMA.md`; models + migrations | Seeder + model relationship tests | **Pass** | None |
| Required seed data | `DatabaseSeeder`, idempotent ERP seeders | `ErpSeedersTest`, Calacatta Houston/Dallas counts | **Pass** | None |
| Sales / Purchasing roles | `UserRole`, gates, `ToolRegistry::definitionsFor` | `ErpGateAuthorizationTest`, `ToolLayerTest`, API tests | **Pass** | None |
| Sanctum authentication | `routes/api.php` `auth:sanctum`, token controller | `AuthApiTest`, `SanctumFoundationTest` | **Pass** | None |
| Business logic in services | ERP + AI orchestration in `app/Services` | Broad feature coverage | **Pass** | None |
| Validation via Form Requests | `app/Http/Requests/Api/**` for auth, ERP, assistant | Implicit via 422 tests on API | **Pass** | Could add explicit Form Request unit tests (low value) |
| Landed-cost calculation | `LandedCostService` + bcmath | `LandedCostServiceTest`, API tests | **Pass** | None |
| Assistant endpoint | `POST /api/assistant` | `AssistantApiTest` | **Pass** | None |
| Tool calling | `AiToolCallingService`, drivers OpenAI/Claude with fakes | `AiToolCallingServiceTest` | **Pass** | None |
| Tool-level authorization | `AiToolInterface::allowedFor`, `ToolExecutor`, ERP gates | `ToolLayerTest`, `AiToolCallingServiceTest` | **Pass** | None |
| No arbitrary SQL from AI | Tools call services/ORM only; no raw user SQL | Indirect via tool + service tests | **Pass** | Model-supplied **extra** keys are stripped by Laravel validator (not fail-closed); low risk |
| No invented inventory/financial values | Services return DB-backed data; tools return errors when missing | Stock / PO / landed cost tests | **Pass** | Model could still *say* wrong numbers; not fully asserted without LLM eval |
| Follow-up conversation context | `FollowUpQuestionResolver` + `ConversationContextManager` | `ConversationPhase7Test`, `AssistantApiTest` (Houston → Dallas) | **Pass** | None |
| Conversation + tool-call logging | `ConversationService`, `ConversationToolCall`, `ConversationAiInteraction` | Phase 7 + API tests | **Pass** | None |
| AI provider abstraction | `AiProviderInterface`, factory, OpenAI + Claude only | Provider tests | **Pass** | Bedrock, Gemini, Groq **not** implemented (`config/ai.php` notes future) |
| API / provider failure handling | Mapped exceptions, safe messages | Provider + assistant tests | **Pass** | None |
| Draft PO preview + explicit confirmation | Proposal tool + `PurchaseOrderProposalController::confirm` | `PurchaseOrderProposalApiTest`, `DraftPurchaseOrderServiceTest` | **Pass** | None |
| Idempotency | Assistant HTTP idempotency + proposal idempotency | `AssistantApiTest`, `PurchaseOrderProposalApiTest` | **Pass** | Concurrent confirm: see Phase 4.3 audit note |
| Chat frontend | `resources/js/chat/*`, Vite, `routes/web.php` `/` | `npm test`, `npm run build` | **Pass** | None |
| README / submission | Root `README.md` is default Laravel boilerplate | None | **Partial** | Project-specific README not present |

---

## 2. Authentication and authorization (summary)

- **Routes:** All ERP and assistant routes sit behind `auth:sanctum` (`routes/api.php`). Landed cost and proposals use dedicated controllers with service-layer gates.
- **Tools:** `ToolRegistry::definitionsFor($user)` limits which tools enter the model; `ToolExecutor` enforces `allowedFor` and strips forbidden argument keys (`user_id`, `role`, `conversation_key`, etc.).
- **Services:** ERP read/proposal services re-check abilities (see `ErpReadApiTest`, `ErpGateAuthorizationTest`, `ErpServiceSecurityAuditTest`).

**Phase 12 additions:** Regression tests for **Sales context excluding persisted `getLandedCost` tool rows** (defense in depth vs. `ToolResultRedactor`) and **invalid `searchPurchaseOrders` status** rejected as `validation_error`.

---

## 3. AI tools and data integrity (summary)

- Tools are **whitelist-only** (registry); no dynamic SQL or PHP execution path.
- Arguments validated per tool (`ValidatesToolArguments`); executor catches throwables and returns `internal_error` without leaking traces to the client path (see `ToolExecutor`).
- **Known nuance:** Laravel’s validator **ignores undeclared keys** in tool argument arrays, so “extra” fields are dropped rather than rejected. Forbidden keys that match `ToolExecutor::FORBIDDEN_ARGUMENT_KEYS` still hard-fail.

---

## 4. Conversation context (summary)

- **Houston → Dallas:** Covered by `FollowUpQuestionResolver` deterministic city swap (`ConversationPhase7Test::test_follow_up_deterministic_city_swap`) and `AssistantApiTest` follow-up path.
- **Limits:** `ConversationContextManager` applies `AI_CONTEXT_MAX_MESSAGES` and `AI_CONTEXT_MAX_CHARS` (tests for max messages).
- **Sales vs Purchasing tool history:** `includeToolRowForUser` omits `getLandedCost` tool messages for Sales — now covered by **`test_sales_context_excludes_prior_get_landed_cost_tool_rows`** and **`test_purchasing_context_includes_get_landed_cost_tool_rows`**.

---

## 5. Draft purchase-order workflow (summary)

- Proposal creation only via tool + trusted server keys; confirmation only via **`POST .../confirm`** with idempotency — see `PurchaseOrderProposalApiTest` and `DraftPurchaseOrderServiceTest`.
- **Concurrency under parallel database sessions** is explicitly called out as a limitation in `docs/PHASE4-ERP-SERVICE-AUDIT.md`; PHP tests use transactions.

---

## 6. Idempotency (summary)

- **Assistant:** `Idempotency-Key` + `conversation_id` with lock + stored replay (`AssistantApiTest`).
- **Proposal confirm:** Idempotency header and DB uniqueness — `PurchaseOrderProposalApiTest`.
- **Queue:** No `ShouldQueue` jobs found under `app/` (`grep` for `dispatch`/`ShouldQueue` empty). Assistant runs **synchronously** in the HTTP request; Redis queue is for **other** Laravel jobs if added later.

---

## 7. Prompt injection and AI security (summary)

- **Server-side enforcement** is the real boundary: tools and ERP services enforce role and data rules regardless of user text. Tests already cover Sales denied `getLandedCost` in the tool loop (`AiToolCallingServiceTest`).
- **Residual risk:** A model could **hallucinate** numbers in free text even when tools deny; mitigations are tool-grounded flows and redaction, not 100% provable in unit tests without LLM evaluation.
- Phase 12 added **invalid status string** tool test as a stand-in for “malformed / injection-like” arguments.

---

## 8. Sensitive data and logging (summary)

- Supplier bank details: encrypted casts + hidden from serialization (`SupplierBankDetailsEncryptionTest`); `ToolResultRedactor` strips sensitive keys from persisted tool results.
- API resources and proposal responses tested for sensitive field absence (`PurchaseOrderProposalApiTest`, `ErpServiceSecurityAuditTest`).

---

## 9. Landed cost and overdue PO rules (verified in tests)

- **Landed cost:** Value-based freight allocation — `LandedCostServiceTest` (multi-line, rounding, remainder, zero/invalid cases).
- **Overdue:** Status `ordered`, ETA not null, ETA strictly before `as_of` — `OverduePurchaseOrderServiceTest`, API tests.

---

## 10. Provider architecture (summary)

- **Implemented:** `openai`, `claude` only. **Not implemented:** Bedrock, Gemini, Groq (`config/ai.php` comments).
- Factory rejects unknown providers (`AiProviderFactoryTest`).
- Tests use **`OpenAI::fake`** and HTTP fakes — no live API keys.

---

## 11. Queue and Docker consistency (summary)

| Item | Application default / doc | Notes |
|------|---------------------------|--------|
| `QUEUE_CONNECTION` | `.env.example` suggests `redis` | PHPUnit forces `sync` |
| Docker `queue` service | `queue:work redis --tries=3` | Matches `redis` driver in `config/queue.php` |
| `retry_after` | 90s in `config/queue.php` for redis | Align with job timeout if async jobs are added later |

**Docker runtime:** Still **not validated** in this environment (see Phase 11).

---

## 12. Bonus feature assessment

| Bonus | Status | Evidence | Tests | Remaining work |
|-------|--------|----------|-------|----------------|
| Automated Pest/PHPUnit tests | **PHPUnit** (not Pest) | `tests/`, `phpunit.xml` | 179 tests | Optional: adopt Pest |
| Minimal chat page | Implemented | `resources/views/chat.blade.php`, JS | `npm test`, build | None |
| Long-running AI via queues | **Not implemented** | No queued assistant jobs in `app/` | N/A | Major feature; deferred |
| Prompt-injection protection | **Partial** | Server-side auth + tool gates + redaction | Tool + API tests | Optional LLM-eval harness |
| Streaming responses | **Not implemented** | Request/response JSON | N/A | Separate phase |

---

## 13. Tests added (Phase 12)

| Test | File | Intent |
|------|------|--------|
| `test_sales_context_excludes_prior_get_landed_cost_tool_rows` | `tests/Feature/AI/Conversations/ConversationPhase7Test.php` | Sales must not see `getLandedCost` tool payloads when building AI context |
| `test_purchasing_context_includes_get_landed_cost_tool_rows` | Same | Purchasing still receives landed-cost tool rows |
| `test_tool_arguments_cannot_inject_invalid_status_strings` | `tests/Feature/AI/Tools/ToolLayerTest.php` | Malformed `status` rejected (`validation_error`) |

**Defects fixed:** None required; changes are **regression tests** only.

---

## 14. Regression test results (Phase 12 run)

| Command | Result |
|---------|--------|
| `composer validate --no-check-publish` | **Valid** |
| `php artisan test` | **179 passed (539 assertions)** |
| `npm test` | **ok** (`parsers.selfcheck`) |
| `npm run build` | **Success** (Vite production build) |

Test DB remains **`stone_erp_ai_test`** per `phpunit.xml`. **No `migrate:fresh`** against `stone_erp_ai`.

---

## 15. Remaining risks and limitations

1. **Docker / Redis queue:** Compose files are static-only verified here; run on a Docker host before production.
2. **Assistant async:** Synchronous request path; long calls block HTTP — no queue offload unless added later.
3. **README:** Still Laravel default; submission may need a project-specific README.
4. **Tool argument strictness:** Extra keys are ignored, not rejected (Laravel default).
5. **LLM hallucination:** Not fully testable without evaluation tooling.

---

## 16. Recommended next phase

- Add **project README** (architecture, env, scripts, API overview).
- Optional: **queue-backed assistant** job + streaming (large effort).
- **Docker smoke test** on a machine with Docker Desktop/Linux.
- Optional **Pest** migration or parallel CI matrix.

---

## 17. Files changed in Phase 12

- `tests/Feature/AI/Conversations/ConversationPhase7Test.php` — two new tests.
- `tests/Feature/AI/Tools/ToolLayerTest.php` — one new test.
- `docs/PHASE12-INTEGRATION-SECURITY-AUDIT.md` — this document.
