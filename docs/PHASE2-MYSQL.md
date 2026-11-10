# Phase 2 — Project-local MySQL 8.4 LTS

Portable **MySQL Community Server 8.4.13 LTS** for **StoneERP-AI-Assistant**, independent of XAMPP MariaDB. No Windows service, no administrator rights, no network exposure beyond localhost.

## Version and source

| Item | Value |
|------|--------|
| Product | MySQL Community Server **8.4 LTS** |
| Version | **8.4.13** |
| Official listing | https://dev.mysql.com/downloads/mysql/8.4.html |
| Package | `mysql-8.4.13-winx64.zip` |
| CDN download URL | https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.13-winx64.zip |

### Checksum verification

Oracle publishes an **MD5** checksum on the official download page for this ZIP (not SHA-256):

| Algorithm | Expected (official) | Result at install |
|-----------|---------------------|-------------------|
| MD5 | `7b94489d5524dea9b27097068bea25a8` | **Match** |
| SHA-256 | *(not published by Oracle for this artifact)* | Recorded in `tools/mysql/INSTALL-SOURCE.txt` for local reference only |

GPG signature verification is also documented by Oracle if you require an additional integrity check.

## Directory layout

```text
tools/mysql/
├── my.ini                 ← committed configuration (no secrets)
├── INSTALL-SOURCE.txt     ← provenance and checksums
├── server/                ← extracted MySQL binaries (gitignored)
├── data/                  ← InnoDB data files (gitignored)
└── logs/
    └── error.log          ← server log (gitignored)
```

Download cache (gitignored):

`tools/.download-cache/mysql-8.4.13-winx64.zip`

## Network and port

| Setting | Value |
|---------|--------|
| **Port** | **3307** (3306 left for XAMPP if used elsewhere) |
| **bind-address** | **127.0.0.1** only |
| **MySQL X Protocol** | Disabled (`mysqlx=0`) |
| **LOCAL INFILE** | Disabled (`local_infile=0`) |
| Charset / collation | `utf8mb4` / `utf8mb4_0900_ai_ci` |

Confirm binding:

```powershell
netstat -an | findstr 3307
```

Expect `127.0.0.1:3307` in `LISTENING` state, not `0.0.0.0:3307`.

## Management scripts

Run from the **project root**:

| Script | Purpose |
|--------|---------|
| `scripts\mysql-start.cmd` | Start project `mysqld.exe` using `tools/mysql/my.ini` |
| `scripts\mysql-stop.cmd` | Graceful shutdown via `mysqladmin … shutdown` (prompts for root password) |
| `scripts\mysql-status.cmd` | Port check + optional `SELECT VERSION()` (prompts for root password) |

These scripts **do not** start or stop XAMPP MariaDB.

## Temporary root password (first login)

The data directory was initialized with:

```text
mysqld --defaults-file=tools/mysql/my.ini --initialize
```

MySQL generates a **one-time temporary root password** for `root@localhost`. It is **not** stored in this documentation or in Git.

Where to find it:

1. The console output from the **initialization** command (line containing `A temporary password is generated for root@localhost`).
2. After the first server start, also check `tools/mysql/logs/error.log` for related notes.

If the temporary password was lost, use Oracle’s documented **password reset** procedure for a portable instance (stop server, restart with `--init-file` or similar) — do not commit reset scripts containing passwords.

## Change the temporary root password

Start the server, then connect (you will be prompted for the temporary password):

```powershell
cd D:\MARCOM_PROJECTS\PrivateProjects\StoneERP-AI-Assistant
.\scripts\mysql-start.cmd

tools\mysql\server\bin\mysql.exe --defaults-file=tools\mysql\my.ini --protocol=tcp -h 127.0.0.1 -P 3307 -u root -p
```

At the MySQL prompt, set a strong personal password (example — replace placeholders yourself):

```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY 'your_new_strong_password';
FLUSH PRIVILEGES;
```

Do not paste real passwords into tickets, Git, or markdown files.

## Create application database and user (after root password is set)

**Not done in Step 2A.** When ready (Step 2B), connect as root and run SQL similar to:

```sql
CREATE DATABASE stoneerp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER 'stoneerp_app'@'127.0.0.1' IDENTIFIED BY 'choose_a_strong_app_password';
GRANT ALL PRIVILEGES ON stoneerp.* TO 'stoneerp_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Then update Laravel `.env` (`DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3307`, etc.) — **only after** you verify connectivity.

## Laravel and PHP

- Laravel `.env` was **not** modified in Step 2A.
- Use project PHP for Artisan: `.\scripts\dev.cmd php artisan …`
- `pdo_mysql` is enabled in `tools/php/php.ini`.

## Git — never commit

- `tools/mysql/server/` (binaries)
- `tools/mysql/data/` (database files, keys, pid)
- `tools/mysql/logs/` (error log)
- `tools/.download-cache/` (ZIP archives)
- `.env` (Laravel secrets)

**Safe to commit:** `tools/mysql/my.ini`, `tools/mysql/INSTALL-SOURCE.txt`, `scripts/mysql-*.cmd`, this document.

## XAMPP

XAMPP MariaDB under `C:\xampp\` was not installed, configured, or started by this step. Use **port 3307** for this project’s MySQL only.

## Troubleshooting

| Issue | Action |
|-------|--------|
| Port 3307 in use | Stop the conflicting process or report conflict; do not change port without updating `my.ini` and docs. |
| Start script says failed but port listens | Wait a few seconds; check `error.log`. Earlier `timeout` in non-interactive shells was replaced with `ping` delay. |
| `Access denied` for root | Use temporary password or the password you set with `ALTER USER`. |
| Laravel still on SQLite | Expected until `.env` is updated in a later step. |
