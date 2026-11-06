# Phase 2 — Laravel MySQL database connection

Laravel is configured to use the **project-local MySQL 8.4 LTS** instance (not XAMPP MariaDB).

## MySQL server

| Item | Value |
|------|--------|
| Product | MySQL Community Server **8.4.13** LTS |
| Host | `127.0.0.1` |
| Port | **3307** |
| Database name | **stone_erp_ai** |
| Application user | **stoneerp_app** (not `root`) |

Start/stop the server from the project root:

```powershell
.\scripts\mysql-start.cmd
.\scripts\mysql-stop.cmd
.\scripts\mysql-status.cmd
```

See [PHASE2-MYSQL.md](./PHASE2-MYSQL.md) for installation and root password management.

## Laravel environment variables

Set in **local** `.env` (gitignored):

| Variable | Purpose |
|----------|---------|
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3307` |
| `DB_DATABASE` | `stone_erp_ai` |
| `DB_USERNAME` | `stoneerp_app` |
| `DB_PASSWORD` | Password for **stoneerp_app** only |

`config/database.php` reads these via `env()`; no code changes are required for the default MySQL connection.

`.env.example` lists the same connection settings with a **placeholder** password for new developers. Copy to `.env` and fill in real values locally.

## Why a separate application user?

- **Least privilege:** `stoneerp_app` should access only `stone_erp_ai`, not all server databases.
- **Security:** The MySQL **root** password stays for administration; Laravel never needs root in production-like setups.
- **Auditability:** Application access is tied to a dedicated account you can rotate without sharing root credentials.

## Configure your local password

1. Open `.env` in the project root (never commit this file).
2. Replace the placeholder `DB_PASSWORD` with the password you chose for **`stoneerp_app`** when you created the MySQL user.
3. Do **not** use the MySQL root password for `DB_PASSWORD`.

Clear cached config after editing `.env`:

```powershell
.\scripts\dev.cmd php artisan config:clear
```

## Test the connection (after password is set)

Ensure MySQL is running on port 3307, then:

```powershell
cd D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant
.\scripts\dev.cmd php artisan config:clear
.\scripts\dev.cmd php artisan db:show
```

Alternative:

```powershell
.\scripts\dev.cmd php artisan tinker --execute="DB::connection()->getPdo(); echo 'connected';"
```

If authentication fails, verify `stoneerp_app` can log in with the MySQL client (same host, port, and database) before debugging Laravel.

## Connection verification (Step 2B.1)

Verified with project-local PHP:

```powershell
.\scripts\mysql-start.cmd
.\scripts\dev.cmd php artisan db:show
```

| Result | Value |
|--------|--------|
| Connection | **Succeeded** |
| Driver | `mysql` |
| Server | **MySQL 8.4.13** |
| Database | `stone_erp_ai` |
| Host / port | `127.0.0.1` / **3307** |

Before migrations, `db:show` reported **0** application tables (empty database). The earlier `migrate:status` message **Migration table not found** was expected because no migration had run yet.

## Initial Laravel migrations (Step 2B.1)

Executed (standard Laravel 12 skeleton only — no custom ERP migrations):

```powershell
.\scripts\dev.cmd php artisan migrate --no-interaction
.\scripts\dev.cmd php artisan migrate:status
```

| Migration file | Status | Tables created |
|----------------|--------|----------------|
| `0001_01_01_000000_create_users_table.php` | Ran (batch 1) | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table.php` | Ran (batch 1) | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table.php` | Ran (batch 1) | `jobs`, `job_batches`, `failed_jobs` |

Laravel also created the **`migrations`** tracking table during the first run.

After migration, `db:show` reported **9** tables total (8 application/framework tables + `migrations`).

**Not run:** seeders, `migrate:fresh`, `migrate:refresh`, `db:wipe`, or any custom ERP schema.

## What is still out of scope

- Custom ERP tables and business logic.
- Redis or cache driver changes beyond existing Laravel defaults.
- Passwords in Git, scripts, or this documentation.

## Related docs

- [PHASE2-MYSQL.md](./PHASE2-MYSQL.md) — portable MySQL install
- [PHASE2-LARAVEL.md](./PHASE2-LARAVEL.md) — Laravel install
- [PHASE1-ENVIRONMENT.md](./PHASE1-ENVIRONMENT.md) — PHP and Composer
