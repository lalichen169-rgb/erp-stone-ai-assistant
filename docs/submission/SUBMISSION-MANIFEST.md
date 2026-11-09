# Submission package manifest — Stone ERP AI Assistant

This manifest lists interviewer-facing deliverables, how they are produced, and what remains environment-dependent. It contains **no credentials or secrets**.

## 1. Submission documents (Word, `.docx`)

| # | File | Purpose |
|---|------|---------|
| 01 | `01-Project-Overview-and-Architecture.docx` | Project scope, architecture, and high-level design for reviewers. |
| 02 | `02-Business-Workflows-and-User-Guide.docx` | Business workflows and end-user oriented guidance. |
| 03 | `03-Installation-and-Developer-Setup.docx` | Developer setup and local environment notes (Windows-oriented). |
| 04 | `04-API-Security-and-Testing-Reference.docx` | API surface, security themes, and testing reference. |
| 05 | `05-Interview-and-Demonstration-Guide.docx` | Interview and demonstration walkthrough. |
| 06 | `06-Final-Acceptance-and-Interview-Preparation.docx` | Phase 16.2-style acceptance baseline and interview preparation (no live AI required to read or regenerate companion material). |
| 07 | `07-Complete-Technical-Interview-Handbook.docx` | Dense, source-backed technical handbook for interviews (inventory, routes, Q&amp;A sections). |

**Deliverables vs local tools:** The seven `.docx` files are **submission deliverables**. They are **not** application source code; regenerate them with the scripts in section 4 when the repository changes materially.

## 2. README and source repository

- **README (clone onboarding):** repository root `README.md` — setup commands, feature summary, limitations, and links to submission docs.
- **Source remote (this audit):** `origin` → `https://github.com/lalichen169-rgb/stone-erp-ai-assistant.git` — treat published URL as authoritative when you cut a release.

## 3. Generated diagram assets

These PNG files under `docs/submission/assets/generated/` are **inputs** to the Word generators (embedded or referenced during build):

- `fig01_high_level_arch.png`
- `fig02_tool_path.png`
- `fig03_follow_up.png`
- `fig04_draft_po.png`
- `fig05_er.png`
- `fig06_docker.png`
- `fig07_provider.png`
- `fig08_laravel_layers.png`
- `fig09_auth.png`
- `fig10_phase16_readiness.png`
- `fig_phase16b_api_sequence.png`
- `fig11_async_ollama_lifecycle.png`
- `fig12_recovery_failure.png`

**Deliverables vs local tools:** These images are **generated artifacts**. Keep them under version control if the submission DOCX should open with embedded diagrams without re-running Python; otherwise regenerate from the scripts after clone.

## 4. Documentation generator scripts

Regenerate submission Word documents (requires Python packages: `python-docx`, `matplotlib`, `pillow`):

| Script | Role |
|--------|------|
| `tools/build_phase13_submission.py` | Builds DOCX **01–06** (and drives shared diagrams) per Phase 13 submission automation. |
| `tools/build_phase16_interview_handbook.py` | Builds DOCX **07** (technical interview handbook). |

Regeneration uses **no live AI APIs** and **no Docker** by itself; it reads the repository and produces documents.

## 5. Local environment scripts (Windows)

| Script | Role |
|--------|------|
| `scripts/dev.cmd` | Preferred entry point for **project-local PHP** and Composer (`scripts\dev.cmd php …`, `scripts\dev.cmd composer …`). |
| `scripts/dev.ps1` | PowerShell counterpart to `dev.cmd`. |
| `scripts/npm-user.cmd` | Runs **npm** via a user-scoped Node version (e.g. NVM under `%USERPROFILE%\nvm\…`); use `scripts\npm-user.cmd test` and `scripts\npm-user.cmd run build` when not using a global Node. |

These are **local developer tooling**, not Laravel runtime code.

## 6. Important runtime prerequisites

- **PHP** ^8.3 and **Composer** dependencies (`composer install`).
- **MySQL** 8 (application and tests; test DB name is defined in `phpunit.xml`).
- **Redis-compatible** client where configured (e.g. Memurai on Windows); queue usage is optional for core flows.
- **Node.js** for the Vite frontend (`npm install`, `npm run build` or `scripts\npm-user.cmd run build`).
- **`.env`** from `.env.example` — never commit real secrets.

## 7. Verified test and build results (historical snapshots)

**Phase 16.6E** (async assistant reliability milestone — re-run before interviews):

| Check | Result |
|-------|--------|
| Backend | `php artisan test` → **294** tests, **862** assertions |
| Redis probe integration | `tests/Feature/Queue/RedisQueueWorkerIntegrationTest.php` — harmless job with separate worker (not Ollama E2E) |
| Frontend self-checks | `scripts\npm-user.cmd test` — parsers + assistant polling + recovery self-checks |
| Production frontend build | `scripts\npm-user.cmd run build` — Vite succeeded |
| Composer | `composer validate` — OK |

**Earlier Phase 16.4E:** 182 tests / 545 assertions — superseded by 16.6E counts for interview citation unless you re-run and record new numbers.

Re-run these commands after major changes; documentation phases may update prose **without** re-running the suite.

## 8. Explicitly unverified or deferred (do not claim as done)

| Category | Status |
|----------|--------|
| Docker / Docker Compose runtime on primary dev host | Documented only |
| Live OpenAI, Claude, or Ollama inference in CI | Not required; tests use fakes/mocks |
| Browser E2E / UI acceptance checklist | Manual |
| Concurrent idempotency stress | Not fully verified |
| Ollama worker end-to-end (real model + Redis worker) | Not verified as acceptance gate |
| Abrupt SIGKILL worker recovery | Not directly verified |
| `Queue::fake()` recovery tests | Prove dispatch counts, not production Redis semantics |

**Implemented (Phase 16.6):** When `AI_PROVIDER=ollama`, assistant uses Redis async (`ProcessOllamaAssistantRun`). OpenAI and Claude remain synchronous.

- **Response streaming** — not implemented.
- **Bedrock / Gemini / Groq** — not implemented (see `config/ai.php`).

## 9. Checklist — preparing the final repository submission

- [ ] All seven DOCX files present under `docs/submission/` and open as valid Office packages.
- [ ] `docs/submission/assets/generated/` contains required PNGs if diagrams must render offline.
- [ ] `README.md` lists all seven submission documents and matches this manifest.
- [ ] Run `composer validate --no-check-publish` (if publishing PHP package metadata).
- [ ] Run `.\scripts\dev.cmd php artisan test` — capture counts for release notes if they change.
- [ ] Run frontend self-check and production build (`scripts\npm-user.cmd` or global npm).
- [ ] Confirm `.env` and `docker/compose.env` are **not** committed; confirm `.gitignore` is intact.
- [ ] Decide whether to commit **Phase 16.2** artifacts: `tools/phase162_acceptance_api.php` (source) vs `tools/phase162_acceptance_api.json` (regenerated output — prefer re-run over stale commit).
- [ ] Resolve root-level empty `php` file (optional delete or ignore in a **future** change — not required for app behavior).
- [ ] Do not commit `tools/__pycache__/` or other bytecode caches (now covered in `.gitignore`).

---

*End of manifest.*
