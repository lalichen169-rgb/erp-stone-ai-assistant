# Phase 15.2B — GitHub publication report

**Date:** 2026-11-06 (local)

## 1. Repository URL

https://github.com/lalichen169-rgb/stone-erp-ai-assistant.git

## 2. Repository visibility

**Private** — confirmed by the project owner before publication. (This report was written from the local environment; automated `gh` API confirmation was not available on the machine used for the push.)

## 3. Branch

`main`

## 4. First commit

| Field | Value |
| --- | --- |
| **Message** | `Initial commit: Stone ERP AI Assistant challenge implementation` |
| **Full hash** | `86f17a8b409817018dd7146d9cea644da0f47d1d` |
| **Short hash** | `86f17a8` |

**Note:** The committed object includes a `Co-authored-by: Cursor <cursoragent@cursor.com>` trailer added by the tooling environment. The first line of the message matches the requested text.

## 5. Push result

- **Status:** Succeeded (no `--force`).
- **Updates pushed:** Initial publish (`86f17a8` → `origin/main`), then follow-up doc commit (`7c88d96` → `origin/main`).
- **Remote:** `origin` → `https://github.com/lalichen169-rgb/stone-erp-ai-assistant.git` (HTTPS, no embedded credentials in the URL).
- **Branch setup:** Local `main` tracks `origin/main`.

## 6. File count published

**First commit (`86f17a8b409817018dd7146d9cea644da0f47d1d`):** **288** tracked files (count via `git ls-tree -r --name-only` at that commit).

**Current tip of `main` (includes this report):** **289** tracked files at `7c88d963288f041a3079933ab75c9df8c7114a2f`.

**Included as required (in the initial publish commit):**

- **Five DOCX** under `docs/submission/*.docx`
- **Nine** generated architecture PNGs under `docs/submission/assets/generated/*.png`
- **Lock files:** `composer.lock`, `package-lock.json`

## 7. Security exclusions verified

The following **exact paths are not** in the published tree:

- `.env`
- `.env.testing`
- `docker/compose.env`
- `tools/composer/composer.phar`

**Also verified:** no `vendor/` or `node_modules/` paths under the published tree (dependencies install from lockfiles locally).

**Examples only (safe):** `.env.example`, `.env.testing.example`, `docker/compose.env.example` are present by design.

**Staged-file audit:** Before commit, documentation was sanitized so staged content did not include OpenAI-style key-prefix examples in live form; a re-check for `sk-proj-` in the staged diff was clean.

## 8. Working tree status (after publication)

- **Tracking:** After the documentation commit was pushed, local `main` matched `origin/main` at `7c88d963288f041a3079933ab75c9df8c7114a2f`. The **initial GitHub publish** remains the root commit `86f17a8b409817018dd7146d9cea644da0f47d1d`; this publication report was added in commit `7c88d963288f041a3079933ab75c9df8c7114a2f` (`docs: add Phase 15.2B GitHub publication report`).
- **Untracked:** A stray untracked file `php` (zero-byte or junk) existed at the repo root and was **not** published. Remove or add to `.gitignore` locally if desired.

## 9. Remaining limitations

- **GitHub UI spot-check:** Not performed from this environment; confirm in the browser that branches, files, and submission assets appear as expected.
- **`gh` CLI:** Not available here, so repo visibility was not double-checked via GitHub API from the agent session.

---

*No secrets, credentials, or API key material are recorded in this document.*
