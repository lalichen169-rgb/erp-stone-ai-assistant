# Phase 2 — Project-local Memurai (Redis-compatible) for Laravel

Portable **Memurai for Redis 8.2.0** (Developer Edition) for **StoneERP-AI-Assistant**, used as the local Redis-compatible server for Laravel queues. No Windows service, no administrator rights for daily operation, no exposure beyond localhost.

## Role in this project

| Concern | Local dev (this machine) | Deployment / CI |
|---------|---------------------------|-------------------|
| Redis-compatible server | **Memurai** under `tools/memurai/` | **Official Redis** Docker image (e.g. `redis:7-alpine`) in Docker Compose |
| Laravel client | **Predis** (`predis/predis` ^3.7) | Same Predis or PhpRedis, configured via `.env` |
| Queue driver | `QUEUE_CONNECTION=redis` | `QUEUE_CONNECTION=redis` |
| Cache / session | `database` (not Redis in Phase 2C) | As chosen per environment |

Memurai is the [Redis-documented native option for Windows](https://redis.io/docs/latest/operate/os_and_stack/install/archive/install-redis/install-redis-on-windows/). Docker and WSL are not used on this developer workstation, so Memurai replaces a containerized Redis for local work only.

## Version

| Item | Value |
|------|--------|
| Product | Memurai for Redis **Developer Edition** |
| Memurai version | **8.2.0** |
| Redis-compatible API | **8.2.10** (per server `INFO` / logs) |
| Install location | `tools/memurai/` |
| Config (active) | `tools/memurai/memurai.conf` |
| Data directory | Relative `dir ./` → resolved under `tools/memurai/` when started from that folder |

## Network

| Setting | Value |
|---------|--------|
| **Bind** | **127.0.0.1** only |
| **Port** | **6379** |
| **Authentication** | Disabled in current dev config |
| **Windows service** | **Not used** (standalone process) |

Confirm binding:

```powershell
netstat -an | findstr 6379
```

Expect `127.0.0.1:6379` in `LISTENING` state, not `0.0.0.0:6379`.

## Management scripts

Run from the **project root** (same pattern as MySQL scripts):

| Script | Purpose |
|--------|---------|
| `scripts\memurai-start.cmd` | Start `tools\memurai\memurai.exe` with `memurai.conf`; working directory `tools\memurai` |
| `scripts\memurai-status.cmd` | Process PID, port, and `PING` |
| `scripts\memurai-stop.cmd` | Stop **only** this project's `tools\memurai\memurai.exe` (never by port alone) |

PowerShell implementation: `scripts\memurai.ps1` (invoked by the `.cmd` wrappers with `-ExecutionPolicy Bypass`).

If PowerShell script execution is blocked, use the `.cmd` wrappers (recommended), or:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\memurai.ps1 status
```

### Start

```powershell
.\scripts\memurai-start.cmd
```

- If project Memurai is **already running**, the script reports that and does **not** start a second instance.
- If **6379 is in use by another program**, the script **refuses** to start (it does not kill foreign processes).
- Startup waits up to **15 seconds** for `PING` → `PONG`.
- The server keeps running after the script exits (background process).

### Status

```powershell
.\scripts\memurai-status.cmd
```

Shows project `memurai.exe` PID (when running), `127.0.0.1:6379` listen state, and `memurai-cli PING`.

### Stop

```powershell
.\scripts\memurai-stop.cmd
```

- Sends `SHUTDOWN` via `memurai-cli` when `PING` works, then waits for the **project** process to exit.
- Does **not** terminate processes based only on port 6379.
- If Memurai was started manually and cannot be matched to `tools\memurai\memurai.exe`, the script prints manual stop guidance instead of killing an unknown process.

Runtime PID hint (optional): `tools/memurai/run/memurai.pid` (gitignored; validated against executable path before use).

## Laravel integration

### Environment (no secrets in docs)

Typical local `.env` keys:

- `REDIS_CLIENT=predis`
- `REDIS_HOST=127.0.0.1`
- `REDIS_PORT=6379`
- `REDIS_PASSWORD=` (empty when auth is disabled)
- `QUEUE_CONNECTION=redis`
- `CACHE_STORE=database`
- `SESSION_DRIVER=database`

Laravel reads Redis settings from `config/database.php` (`redis` connections). The queue uses the `redis` connection defined in `config/queue.php`.

### Verify Redis from Laravel

Use project-local PHP:

```powershell
.\scripts\dev.cmd php artisan config:clear
```

Then confirm Predis can reach Memurai (example pattern used in Phase 2C.2): bootstrap the app and call `Redis::connection()->ping()` — expect **PONG**.

### Queue worker

Memurai must be running before workers consume jobs:

```powershell
.\scripts\memurai-start.cmd
.\scripts\dev.cmd php artisan queue:work redis
```

For a single job (testing):

```powershell
.\scripts\dev.cmd php artisan queue:work redis --once --tries=1
```

Use a dedicated queue name when testing (e.g. `--queue=redis-verification`) so you do not mix with other queues.

### Verify queue processing

1. Ensure Memurai is up: `.\scripts\memurai-status.cmd` → **PONG**.
2. Dispatch a `ShouldQueue` job to the `redis` connection (application or temporary test job).
3. Run `queue:work redis` (or `--once`).
4. Confirm the job's side effect and that the Redis list length for that queue returns to zero.
5. Check `failed_jobs` in MySQL if something did not run (failed driver remains **database**).

## Developer Edition licence limits

Evidence from the running server and `tools/memurai/memurai-license-dev.txt`:

- **Development / test only** — not for production.
- **Automatic shutdown after 10 days uptime** — restart Memurai when required.
- Other Developer limits (client IP count, memory cap) apply per [Memurai FAQ](https://www.memurai.com/faq).

## Docker Compose (deployment)

Production and CI should use an **official Redis image**, not Memurai inside the container:

```yaml
# Illustrative — actual compose file may live in a later phase
services:
  redis:
    image: redis:7-alpine
    # internal network; auth via secrets/env in real deployments
```

Local Memurai and container Redis share the same Laravel configuration shape; only host/port/credentials differ by environment.

## Related documentation

- `docs/PHASE2-MYSQL.md` — project-local MySQL on port 3307
- `docs/PHASE2-DATABASE.md` — Laravel database setup
- `scripts/dev.cmd` — project-local PHP and Composer

## Troubleshooting

| Symptom | Check |
|---------|--------|
| Laravel cannot connect to Redis | Memurai running? `.\scripts\memurai-status.cmd` |
| `Connection refused` on 6379 | Run `.\scripts\memurai-start.cmd` |
| Port in use, start refused | Another app owns 6379; do not use `memurai-stop` unless it is project Memurai |
| Queue jobs not running | `QUEUE_CONNECTION=redis`, worker running, Memurai up |
| After 10 days | Restart Memurai (Developer licence) |

Log file: `tools/memurai/memurai-log.txt` (gitignored).
