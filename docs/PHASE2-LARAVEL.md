# Phase 2 — Laravel 12 installation

This document records how Laravel was installed into **StoneERP-AI-Assistant** while preserving the Phase 1 project-local PHP and Composer toolchain.

## Installed versions

| Component | Version |
|-----------|---------|
| **Laravel Framework** | **12.71.0** |
| **laravel/laravel** skeleton | **12.0.0** (Composer project template) |
| **PHP** (project-local CLI) | **8.3.36** NTS x64 |
| **Composer** (project-local PHAR) | **2.11.1** |

PHP requirement in `composer.json`: `"php": "^8.2"` — satisfied by 8.3.36.

## Installation method

1. Verified project-local tooling:
   - `.\scripts\dev.cmd php -v`
   - `.\scripts\dev.cmd composer --version`

2. Attempted Composer signing key import:
   - `.\scripts\dev.cmd composer self-update --update-keys`
   - **Failed** in non-interactive mode (Composer requires an interactive session to fetch pubkeys). TLS and package audit were **not** disabled. Run the same command manually in an interactive terminal when convenient.

3. Created Laravel in a temporary directory (does not touch Phase 1 paths):

   ```powershell
   .\scripts\dev.cmd composer create-project laravel/laravel .laravel-bootstrap ^12.0 --no-interaction --prefer-dist
   ```

   Constraint `^12.0` ensures Laravel **12.x** only (not 13).

4. Verified bootstrap: `laravel/framework` **12.71.0**, PHP **8.3.36**.

5. Merged into project root with **robocopy**, excluding nothing under `tools/`, `scripts/`, or `docs/` (those directories exist only at the root and were not overwritten).

6. Removed `.laravel-bootstrap` after `vendor/autoload.php` and application trees were confirmed at the root.

**Global Composer and XAMPP PHP were not used.** No system or user `PATH` changes were made.

## Commands for daily development

From the project root:

```powershell
.\scripts\dev.cmd php artisan --version
.\scripts\dev.cmd php artisan about
.\scripts\dev.cmd composer install
.\scripts\dev.cmd composer validate
.\scripts\dev.cmd php artisan serve
```

If PowerShell script execution is allowed:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev.ps1 php artisan --version
```

Always use **`dev.cmd` / `dev.ps1`**, never bare `composer` or `php` on PATH (those may point at XAMPP / global Composer).

## Verification results (post-install)

| Check | Result |
|-------|--------|
| `artisan` at project root | OK |
| `composer.json` / `composer.lock` | OK |
| `vendor/` | OK |
| `.\scripts\dev.cmd php artisan --version` | Laravel **12.71.0** |
| `.\scripts\dev.cmd php artisan about` | PHP **8.3.36**, Environment **local** |
| `.\scripts\dev.cmd composer validate` | `./composer.json is valid` |
| `tools/`, `scripts/`, `docs/` preserved | OK |

### Non-blocking install notes

- **SQLite migrate during `create-project`:** Post-install script reported `could not find driver` for SQLite. The default `.env` uses `DB_CONNECTION=sqlite`. The project-local PHP build can enable `pdo_sqlite` later, or you can switch to MySQL when the database phase is done. Migrations were not required for this step.
- **Pre-existing file:** An empty `php` file at the project root (from before Phase 2) was left untouched; Laravel does not use it.
- **`.env`:** Created from `.env.example` with a generated `APP_KEY`. Do not commit `.env` (listed in `.gitignore`).

## Composer PHAR advisory (Symfony Filesystem)

`composer diagnose` may report **PKSA-g499-cm4q-v35y** on **`symfony/filesystem`** bundled **inside the Composer PHAR** (Composer 2.11.1’s own dependencies), not in the Laravel application’s `vendor/`.

| Question | Answer |
|----------|--------|
| Does this affect the Laravel app? | **No** — Laravel uses its own Symfony components (7.x) via `laravel/framework`; `composer audit` on the project reported no application advisories at install time. |
| Does this affect day-to-day dev? | **Low risk** for normal Packagist installs; the issue concerns path handling with unusual stream-wrapper prefixes inside Composer’s filesystem helper. |
| Recommended action | Periodically run `.\scripts\dev.cmd composer self-update` to pick up a PHAR with patched bundled libraries; optionally run `composer self-update --update-keys` **interactively** once. Do **not** disable audit or TLS. |

## Phase 1 documentation

Environment setup (PHP, Composer, helpers): [PHASE1-ENVIRONMENT.md](./PHASE1-ENVIRONMENT.md).

## Next recommended step

Configure the database layer (start XAMPP MySQL or enable SQLite in project PHP), update `.env` `DB_*` settings, run `.\scripts\dev.cmd php artisan migrate`, then proceed with ERP assistant features — without adding Redis or extra packages until planned.
