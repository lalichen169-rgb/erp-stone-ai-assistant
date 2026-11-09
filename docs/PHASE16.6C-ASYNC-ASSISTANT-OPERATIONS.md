# Phase 16.6C — Async Ollama assistant operations

This runbook describes how to run and recover the **asynchronous Ollama assistant** on Windows (primary dev path) and in optional **Docker Compose**. OpenAI and Claude remain **synchronous** and do not use this worker.

## Prerequisites

| Component | Purpose |
|-----------|---------|
| MySQL 8 | Conversations, `assistant_runs`, ERP data |
| Redis (Memurai on Windows) | Laravel queue backend for `ProcessOllamaAssistantRun` |
| Project PHP 8.3 (`tools/php`) | `scripts\dev.cmd` |
| `.env` | `AI_PROVIDER=ollama`, `QUEUE_CONNECTION=redis`, Redis + DB settings |

## Configuration (source of truth)

From `config/ai.php` (overridable via `.env`):

| Key | Default | Meaning |
|-----|---------|---------|
| `AI_ASSISTANT_QUEUE_CONNECTION` | `redis` | Queue connection for assistant jobs |
| `AI_ASSISTANT_QUEUE` | `default` | Queue name worker must consume |
| `AI_ASSISTANT_JOB_TIMEOUT` | `900` | Job + worker `--timeout` (seconds) |
| `AI_ASSISTANT_RUN_STALE_SECONDS` | `1080` | Stale **processing** threshold (status API) |
| `REDIS_QUEUE_RETRY_AFTER` | `960` (min) | Must exceed worker timeout (`config/queue.php`) |

## Windows — start infrastructure

```bat
scripts\mysql-start.cmd
scripts\memurai-start.cmd
scripts\memurai-status.cmd
```

## Windows — Laravel application

```bat
scripts\dev.cmd composer install
scripts\dev.cmd php artisan migrate
scripts\dev.cmd php artisan serve
```

Frontend (Node 22 project wrapper):

```bat
scripts\npm-user.cmd run build
```

## Windows — Redis queue worker (required for Ollama async)

In a **second terminal** (same project root):

```bat
scripts\queue-worker.cmd
```

Dry-run (prints resolved connection/queue/timeout without starting the worker):

```bat
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\queue-worker.ps1 -DryRun
```

The worker runs:

- `queue:work` on `ai.assistant.async.queue_connection` (default `redis`)
- `--queue` = `ai.assistant.async.queue` (default `default`)
- `--timeout` = `ai.assistant.async.job_timeout_seconds` (default `900`)
- `--tries=1`, `--sleep=3`, `--max-time=3600` (process recycle only)

**Do not** rely on `QUEUE_CONNECTION=sync` in PHPUnit; production/local Ollama dispatch uses **`onConnection('redis')`** explicitly.

## Verify the worker listens to the correct queue

1. Read config (no secrets):

   ```bat
   scripts\dev.cmd php artisan config:show ai.assistant.async.queue --no-ansi
   scripts\dev.cmd php artisan config:show ai.assistant.async.queue_connection --no-ansi
   ```

2. With Memurai running, inspect list length (queue name only):

   ```bat
   scripts\dev.cmd php artisan tinker --execute="echo Illuminate\Support\Facades\Redis::connection()->llen('queues:'.config('ai.assistant.async.queue'));"
   ```

3. Run harmless automated proof (no Ollama):

   ```bat
   scripts\dev.cmd php artisan test tests/Feature/Queue/RedisQueueWorkerIntegrationTest.php
   ```

## Docker Compose (optional)

Copy `docker/compose.env.example` → `docker/compose.env`, set passwords and `APP_KEY`.

Validate YAML (no Docker runtime required):

```bat
docker compose --env-file docker/compose.env config
```

Start stack:

```bat
docker compose --env-file docker/compose.env up -d --build
```

The `queue` service runs the same image as `app`, uses `env_file: docker/compose.env`, and starts `queue:work` with `AI_ASSISTANT_QUEUE_CONNECTION`, `AI_ASSISTANT_QUEUE`, and `AI_ASSISTANT_JOB_TIMEOUT` from the container environment.

**Container runtime was not verified** on the primary Windows dev machine used for recent phases.

## API flow (Ollama only)

1. `POST /api/assistant` → **HTTP 202**, run `queued`, job pushed to Redis.
2. Worker runs `ProcessOllamaAssistantRun` → claims run → `processing` → inference → `completed` or `failed`.
3. Client polls `GET /api/assistant/runs/{runId}` until terminal state.

OpenAI/Claude: **HTTP 200**, no queue job.

## Inspecting runs (safe)

### Artisan (recommended)

```bat
scripts\dev.cmd php artisan tinker --execute="App\Models\AssistantRun::query()->latest()->limit(5)->get(['public_id','status','error_code','created_at','started_at','completed_at']);"
```

### Database (example)

```sql
SELECT public_id, status, error_code, created_at, started_at, completed_at
FROM assistant_runs
ORDER BY id DESC
LIMIT 20;
```

Statuses: `queued`, `processing`, `completed`, `failed`.

## Failed queue jobs

```bat
scripts\dev.cmd php artisan queue:failed
scripts\dev.cmd php artisan queue:retry <uuid>
scripts\dev.cmd php artisan queue:flush
```

Use caution with `retry`/`flush` in shared environments. Assistant jobs use `$tries = 1` by design.

## Queued-run recovery (Phase 16.6E)

The API does **not** inspect Redis to prove a specific job exists (reserved, delayed, and in-flight jobs make list length unreliable).

Policy:

| Setting | Default | Role |
|---------|---------|------|
| `AI_ASSISTANT_QUEUED_GRACE_SECONDS` | 15 | Minimum age before a new run may be re-dispatched |
| `AI_ASSISTANT_QUEUE_DISPATCH_LEASE_SECONDS` | 120 | No re-dispatch within this window after a successful enqueue |
| `AI_ASSISTANT_MAX_QUEUE_DISPATCH_ATTEMPTS` | 5 | Bounded Redis re-dispatch attempts per run; when reached, run is marked `queue_recovery_exhausted` (no infinite `queued`) |
| `AI_ASSISTANT_QUEUED_ABANDON_SECONDS` | 3600 | Documented operator window; exhaustion logic fails the run when **attempt count** reaches the max (not solely by age) |

Re-dispatch is triggered on **status poll**, **202 acceptance**, and **idempotent replay** (same run row, no duplicate run). A short-lived in-request cache key (`assistant:run:dispatching:{id}`) reduces concurrent double-dispatch while one PHP request is inside `dispatchRunToRedisQueue` — it is **not** a guaranteed 30-second cross-process mutex (Phase 16.6F).

**Limitation:** `ProcessOllamaAssistantRun::failed()` runs when Laravel marks the job failed; **SIGKILL** or abrupt worker death may leave a run `processing` until the stale threshold (1080s) on a status poll. The HTTP idempotency cache lock (~120s) does not cover the full job duration; duplicate inference is prevented by the DB queued→processing claim and `$tries = 1`. `ShouldBeUnique` was removed from the job so bounded **re-dispatch** can enqueue another payload for the same run; the database claim still prevents double execution. Dispatch lease checks and Redis `dispatch()` are **not** one atomic transaction — concurrent polls may create duplicate queue payloads; only one worker should claim inference.

**Clarifications:** Follow-up questions that need user clarification return **HTTP 200** synchronously (no `assistant_runs` row). Only turns that proceed to inference are queued for Ollama.

**Frontend new chat:** Starting a new conversation clears the pending run id in `sessionStorage`; the worker may still complete the prior run server-side. Use the status API if you kept the run id elsewhere.

## Troubleshooting

| Symptom | Likely cause | Action |
|---------|--------------|--------|
| Run stays **queued** | Worker not running or wrong queue | Start `scripts\queue-worker.cmd`; verify `AI_ASSISTANT_QUEUE` |
| Run stays **processing** | Worker down, slow inference, or stale threshold not reached | Check worker logs; verify Memurai; wait past stale threshold or inspect `started_at` |
| HTTP 202 but never completes | Redis unreachable at dispatch | Check `queue_dispatch_failed` on run; Memurai status |
| Immediate **failed** after 202 | Dispatch exception | Run `error_code` = `queue_dispatch_failed` |
| Frontend stuck after refresh | Worker still processing | Pending run resumes via sessionStorage + status API (same user only) |

### Stale processing

`AssistantRunCoordinator::reconcileStaleProcessing()` runs on **status poll** and acceptance paths. Default **1080s** after `started_at` while `processing` → marks `failed` with `ai_timeout`. This is **longer than** the 900s job timeout so a legitimately running worker is not flagged early.

No automatic destructive batch job is installed; operators rely on polling + optional manual inspection.

### Provider timeout vs queue worker

| Issue | Clues |
|-------|--------|
| **Queue worker** | All runs queued; Redis empty; worker process absent |
| **Job timeout (900s)** | Run failed in worker; Laravel worker log; `assistant_execution_failed` |
| **Stale API recovery** | Run still `processing` in DB until 1080s without completion |

### Restart worker safely

1. Ctrl+C in the worker terminal.
2. Ensure no duplicate workers consume the same queue unless intentional.
3. `scripts\queue-worker.cmd` again — unclaimed `queued` jobs are picked up; `processing` rows depend on claim logic (`$tries = 1`, no blind re-inference).

## Checks without live AI

- `scripts\memurai-status.cmd`
- `scripts\dev.cmd php artisan test tests/Feature/Queue/RedisQueueWorkerIntegrationTest.php`
- `scripts\dev.cmd php artisan test tests/Feature/Api/AssistantAsyncTest.php`
- `scripts\npm-user.cmd test` (polling recovery self-checks)

## Frontend recovery

The chat UI stores `run_id` and **user id** in `sessionStorage` (not the bearer token). After refresh, it resumes polling `GET /api/assistant/runs/{id}` for the same user. Logout clears pending state. Another user's run is never resumed.
