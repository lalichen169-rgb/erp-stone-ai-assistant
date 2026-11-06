# Phase 1 — Project-local PHP and Composer environment

This document describes the portable **PHP** and **Composer** toolchain for **StoneERP-AI-Assistant** on Windows without administrator rights. It does **not** replace or modify XAMPP or your global Composer installation.

## PHP version and build

| Property | Value |
|----------|--------|
| Version | **8.3.36** |
| Thread safety | **NTS** (Non-Thread-Safe) |
| Architecture | **x64** (`PHP_INT_SIZE = 8`) |
| Toolchain | Visual C++ **2019** (VS16), x64 |
| SAPI used | **CLI** (`php.exe`) |

NTS builds are the usual choice for PHP CLI and `php artisan` on Windows. XAMPP’s bundled PHP remains **8.2.26 ZTS** and is unchanged.

## Official distribution source

| Item | Detail |
|------|--------|
| Publisher | The PHP Group — [windows.php.net](https://windows.php.net/) |
| Package | `php-8.3.36-1-nts-Win32-vs16-x64.zip` |
| Download URL | https://windows.php.net/downloads/releases/php-8.3.36-1-nts-Win32-vs16-x64.zip |
| SHA-256 | `1a097d52b7a68622cdc723073b61cc70caf79c941218ff65b2d8441dbc6b10d4` |

A copy of this metadata is stored in `tools/php/INSTALL-SOURCE.txt`.

The ZIP was downloaded once into `tools/.download-cache/` and extracted into `tools/php/`. You may delete the cache folder to save disk space; PHP does not need it at runtime.

## Installation location

```
D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant\tools\php\
├── php.exe              ← CLI entry point
├── php.ini              ← project-local configuration (active)
├── php.ini-development  ← upstream template (reference)
├── php.ini-production   ← upstream template (reference)
├── ext\                 ← extension DLLs (extension_dir)
├── INSTALL-SOURCE.txt   ← install provenance
└── … (runtime DLLs from official ZIP)
```

Nothing under `C:\xampp\` was modified.

## php.ini configuration

**Active file:** `tools/php/php.ini`

**How it was created:** Copied from the official `php.ini-development` template shipped in the ZIP, then adjusted for this project. XAMPP’s `php.ini` was **not** copied.

**Key settings:**

| Directive | Value | Purpose |
|-----------|--------|---------|
| `extension_dir` | `"ext"` | Loads DLLs from `tools/php/ext` (relative to the PHP root) |
| `date.timezone` | `UTC` | Avoids timezone warnings in CLI/Laravel |
| Development defaults | From `php.ini-development` | `display_errors`, `E_ALL`, etc., suitable for local dev |

## Extension configuration

Laravel-required capabilities and how they are provided on this build:

| Requirement | Status | Notes |
|-------------|--------|--------|
| `pdo_mysql` | Enabled | `extension=pdo_mysql` |
| `mbstring` | Enabled | `extension=mbstring` |
| `openssl` | Enabled | `extension=openssl` |
| `curl` | Enabled | `extension=curl` |
| `fileinfo` | Enabled | `extension=fileinfo` |
| `intl` | Enabled | `extension=intl` |
| `zip` | Enabled | `extension=zip` |
| `bcmath` | Built-in | No `extension=` line in stock PHP 8.3 package; appears in `php -m` as **bcmath** |
| `ctype`, `json`, `tokenizer`, `xml` | Built-in | Core / standard modules |

**PDO MySQL:** Confirm with:

```powershell
.\scripts\dev.ps1 php -r "print_r(PDO::getAvailableDrivers());"
```

Expected output includes `mysql`.

## Runtime dependencies (Windows)

PHP 8.3 VS16 x64 requires the **Microsoft Visual C++ 2019 Redistributable (x64)** (`VCRUNTIME140.dll`, etc.). On this machine, `php.exe` starts successfully, which indicates the redistributable is already present (often installed with XAMPP or other dev tools).

If PHP fails to start with a missing DLL error:

1. Install [Microsoft Visual C++ Redistributable for Visual Studio 2015–2022 (x64)](https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist) using a **user-level** or IT-approved installer if available.
2. Ensure you run `tools\php\php.exe` from its directory or via `scripts\dev.ps1` so bundled DLLs in `tools\php\` are found.

## Project-local Composer

### Why Composer is needed

Laravel and most PHP libraries are distributed through [Packagist](https://packagist.org/) and installed with **Composer**. It resolves dependencies, writes `vendor/`, and runs scripts (for example after `composer install`).

### What the Composer PHAR is

`composer.phar` is a single PHP archive: the full Composer application. It is not a separate `.exe`. You always run it **through PHP**:

```text
tools\php\php.exe tools\composer\composer.phar install
```

That is why the PHP version matters: Composer executes with whatever `php.exe` you use.

### Why global Composer is not used for this project

Your machine has **Composer 2.9.8** wired to **XAMPP PHP 8.2.26**. This project targets **PHP 8.3.36**. Using global `composer.bat` could install packages or platform checks against the wrong PHP. The project therefore ships its own PHAR and runs it only via `tools/php/php.exe`.

### Installation layout

```text
tools/composer/
├── composer.phar       ← Composer 2.11.1 application
├── INSTALL-SOURCE.txt  ← download provenance and checksums
├── home/               ← COMPOSER_HOME (config.json, keys)
│   └── config.json
└── cache/              ← COMPOSER_CACHE_DIR (package cache)
```

| Setting | Location / value |
|---------|------------------|
| PHAR | `tools/composer/composer.phar` |
| Version | **2.11.1** (latest stable at install) |
| `COMPOSER_HOME` | `tools/composer/home` (process-local via `dev` scripts) |
| `COMPOSER_CACHE_DIR` | `tools/composer/cache` |
| `secure-http` | `true` (HTTPS required for repos) |
| `audit.abandoned` | `report` (security/abandonment reporting enabled) |
| `platform.php` | `8.3.36` (dependency resolution matches project PHP) |

Official install method: [getcomposer.org/installer](https://getcomposer.org/installer) with **SHA-384** verification, then **SHA-256** check of the resulting PHAR against [latest-stable checksum](https://getcomposer.org/download/latest-stable/composer.phar.sha256sum).

## How to run PHP and Composer

Always use the project helper so the correct PHP, `php.ini`, and Composer PHAR are used.

### Recommended on locked-down Windows (`dev.cmd`)

Batch files are not blocked by PowerShell’s script execution policy:

```powershell
cd D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant

.\scripts\dev.cmd php -v
.\scripts\dev.cmd composer --version
.\scripts\dev.cmd composer diagnose
```

### PowerShell helper (`dev.ps1`)

If `.\scripts\dev.ps1` fails with **running scripts is disabled on this system**, use `dev.cmd` above, or run PowerShell **once per command** without changing machine policy:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev.ps1 composer --version
```

Optional (persistent **CurrentUser** only, no admin): `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned` — only if your company allows it.

```powershell
.\scripts\dev.ps1 php -v
.\scripts\dev.ps1 composer --version
.\scripts\dev.ps1 composer validate
```

`composer validate` requires a `composer.json` in the project root; it will fail until Laravel (or another app) is created.

**Direct invocation** (equivalent if `PHPRC` points at `tools\php`):

```powershell
$env:PHPRC = "D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant\tools\php"
D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant\tools\php\php.exe -v
```

### What `scripts/dev.cmd` and `scripts/dev.ps1` do

1. Resolve `tools\php\php.exe` and `tools\composer\composer.phar` under the project root.
2. Prepend `tools\php` to **`PATH` for the current shell process only** (helps Windows locate companion DLLs).
3. Set **`PHPRC`** to `tools\php` so this tree’s `php.ini` is loaded.
4. Set **`COMPOSER_HOME`** and **`COMPOSER_CACHE_DIR`** under `tools/composer/` so global Composer config is not used.
5. For `php …`, forward arguments to `php.exe`. For `composer …`, run `php.exe composer.phar …` — never `composer.bat` on PATH.

They do **not** change machine-wide or persistent user environment variables.

## Project-local PHP vs XAMPP PHP

| | Project `tools/php` | XAMPP `C:\xampp\php` |
|--|-------------------|----------------------|
| Version | 8.3.36 NTS | 8.2.26 ZTS |
| Config | `tools/php/php.ini` | `C:\xampp\php\php.ini` |
| Purpose | Laravel / Composer / Artisan for this repo | Apache stack, legacy projects |
| Modified in Phase 1 | Yes (new install) | **No** |

When you open a normal terminal, `php` on PATH may still resolve to XAMPP. Use `.\scripts\dev.cmd php …` or explicit paths for this project.

## Troubleshooting

### `dev.ps1` cannot be loaded — scripts disabled

Corporate PowerShell often sets **Restricted** for script execution. Use **`.\scripts\dev.cmd php -v`** instead, or `powershell -ExecutionPolicy Bypass -File .\scripts\dev.ps1 php -v` for a single run.

### Wrong PHP version when running `php -v`

Your global PATH is probably pointing at XAMPP. Use `.\scripts\dev.cmd php -v` or call `tools\php\php.exe` explicitly.

### `php.ini` not loaded from the project

Run `.\scripts\dev.cmd php --ini`. **Loaded Configuration File** should be:

`D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant\tools\php\php.ini`

If not, set `$env:PHPRC` to `tools\php` for that session or use `dev.ps1`.

### Extension fails to load / “Unable to load dynamic library”

1. Confirm the DLL exists in `tools\php\ext\`.
2. Confirm `extension_dir = "ext"` in `tools\php\php.ini`.
3. Run from project root or via `dev.ps1` so relative `extension_dir` resolves correctly.
4. For `intl`, ensure ICU DLLs in `tools\php\` (shipped with the official ZIP) are present and not quarantined by antivirus.

### Missing VCRUNTIME / API-ms-win DLL errors

Install or repair **VC++ 2019 x64 Redistributable** (see Runtime dependencies above). No admin may be required depending on corporate policy.

### Corporate network / SSL errors (Composer)

If `composer diagnose` reports HTTP or SSL failures:

1. Confirm corporate proxy settings if your network requires them (`HTTP_PROXY` / `HTTPS_PROXY` in the **current session only**, or IT-provided Composer `http-proxy` config in `tools/composer/home/config.json`).
2. Optionally set `curl.cainfo` or `openssl.cafile` in `tools/php/php.ini` to your organization’s CA bundle.
3. Do **not** disable `secure-http` or TLS verification unless IT explicitly requires a documented exception.

### Composer diagnose: missing pubkeys

If diagnose shows **Missing pubkey for tags verification**, import keys once:

```powershell
.\scripts\dev.cmd composer self-update --update-keys
```

This updates keys inside `tools/composer/home` only.

### Composer diagnose: vulnerability warnings on Composer itself

`composer diagnose` may audit **Composer’s own bundled libraries**. Updating the PHAR (see below) addresses advisories fixed in newer releases.

### Updating project-local Composer safely

From the project root:

```powershell
.\scripts\dev.cmd composer self-update
```

That replaces `tools/composer/composer.phar` using the same PHP binary. Optionally re-check SHA from [getcomposer.org/download](https://getcomposer.org/download/) after manual downloads.

### Wrong PHP reported by Composer

You ran global `composer` instead of the helper. Use:

```powershell
.\scripts\dev.cmd composer --version
```

The second line must show `PHP version 8.3.36 (...tools\php\php.exe)`.

## Removing portable PHP / Composer (XAMPP unaffected)

1. Close terminals using `dev.cmd`, `dev.ps1`, or direct paths under `tools\`.
2. Delete:
   - `tools\php\`
   - `tools\composer\`
   - Optional: `tools\.download-cache\`
3. Remove `scripts\dev.cmd`, `scripts\dev.ps1`, and this doc if rolling back Phase 1.

Do **not** delete or edit `C:\xampp\` or `%AppData%\ComposerSetup\` unless you intend to change global tooling.

## Validation checklist (completed)

- [x] PHP ≥ 8.3
- [x] x64 CLI
- [x] `php.ini` loaded from `tools/php/php.ini`
- [x] All listed Laravel extensions available
- [x] PDO driver `mysql` available
- [x] Composer PHAR at `tools/composer/composer.phar`
- [x] Composer **2.11.1** runs with **PHP 8.3.36** via `dev` scripts
- [x] Packagist HTTP connectivity OK (`composer diagnose`)

## Next phases

- **Laravel 12:** installed — see [PHASE2-LARAVEL.md](./PHASE2-LARAVEL.md).
- **MySQL / Redis:** not configured yet (database driver / services).
