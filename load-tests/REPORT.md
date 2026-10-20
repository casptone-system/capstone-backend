# ADAMS Load Test Report — 2026-10-21 (local)

**Tool:** Apache JMeter 5.6.3 (non-GUI), plan `load-tests/adams-load-test.jmx`, runner `load-tests/run-load-test.ps1`
**Target:** local — 4× `php -S` workers (127.0.0.1:8001-8004) behind Node round-robin proxy on :8000, MySQL `adams_db`, seeded demo data (4 colleges, 3 programs, 32 documents, 19 users)
**Profiles:** smoke 12 VU · load 55 VU · stress 110 VU (virtual users across all thread groups, 100-200 ms think time)
**Pass criteria:** core error rate < 1% and p95 (non-login) < 2,000 ms

> Local numbers are a **lower bound** (laptop, `APP_DEBUG=true`, file cache/session, single-threaded PHP workers). Re-run on staging before quoting capacity figures: `.\load-tests\run-load-test.ps1 -Profile load -TargetHost <staging-host> -Protocol https -Port 443`

## Results

| Profile | VU | Samples | Duration | Throughput | Core errors* | Avg | p50 | p95 | Max | Verdict |
|---|---|---|---|---|---|---|---|---|---|---|
| smoke | 12 | 259 | 55 s | 4.7 req/s | **0%** | 1,489 ms | ~900 ms | 4,329 ms | 8,757 ms | FAIL (latency) |
| load | 55 | 4,300 | 513 s | 8.4 req/s | **0%** | 4,627 ms | ~4,300 ms | 8,435 ms | 23,219 ms | FAIL (latency) |
| stress | 110 | 8,700 | 1,070 s | 8.1 req/s | **0%** | 9,631 ms | ~9,000 ms | 16,149 ms | 24,142 ms | FAIL (latency) |

\* "Core" excludes the login-stress group (see Finding 2). Every non-login request in every profile returned **HTTP 200** — the system never crashed, timed out (60 s limit), or returned a 5xx on reads, even at 110 VU.

Per-endpoint detail (load / stress p95): all 17 read endpoints cluster tightly (load p95 8.0-9.3 s; stress p95 14.4-17.8 s) — including `GET /api/health` (p50 **4.8 s** at load, **10.4 s** at stress). Full tables: `analyze-results.ps1`, HTML dashboards in `results/<profile>-*/html-report/index.html`.

## Findings

1. **Hard throughput ceiling ≈ 8.5 req/s locally; latency is queueing, not endpoint cost.**
   Throughput is identical at 55 and 110 VU while avg latency doubles (4.6 s → 9.6 s). All endpoints — even `/api/health` — degrade uniformly, so the bottleneck is shared infrastructure: 4 single-threaded `php -S` workers + per-request Laravel bootstrap on this laptop. This is primarily a *local test-harness* ceiling, not proof the app can't scale on php-fpm — but it must be re-measured on staging.

2. **Login is capped at 12 attempts/min per IP (hardcoded) — the only "errors" seen.**
   `AuthController::getLoginIpLimitPerMinute()` returns `12`, keyed by IP only (`login_ip_attempts:<ip>`, throws 422). Login-stress results: load **11×200 / 39×422**, stress **38×200 / 160×422**. Any NAT'd network (a whole campus behind one public IP, or one JMeter injector) shares that budget of 12 logins/minute. Consider keying by IP+email, raising the limit, or using a proper rate-limiter with a longer window — 12 successful *and failed* attempts/min for an entire institution is very low.

3. **Every API request writes a DB row synchronously (audit middleware).**
   `AuditApiActions` → `AuditLogService::log()` does `AuditLog::create()` on *every* request, including GETs and `/api/health`. The three runs generated ~13,000 audit rows (`audit_logs` now 41,198). That's a write transaction competing with reads on every call — and the likely source of Finding 4. Queue it (afterResponse/job), batch it, or skip safe GET/health routes.

4. **MySQL deadlocks under concurrent login/audit writes.**
   8× `SQLSTATE[40001] Deadlock found (1213)` in `storage/logs/laravel.log` during the runs; 2 login requests surfaced as **HTTP 500**. Concurrent `last_login_at`/audit/token writes on hot rows deadlock occasionally — add transaction retry or move those writes off the request path (same fix as #3).

5. **Zero functional failures on reads at 110 VU.** Correctness under load is good; the system degrades by slowing down (queueing) rather than erroring. Max observed latency 24 s stayed under the 60 s timeout.

6. **Minor:** `GET /api/qa/dashboard` returns 403 for superadmin (strict per-role RBAC) — excluded from the plan; verify that's intended.

## Recommendations (priority order)

1. Re-run `load` + `stress` against staging (php-fpm/Apache, `APP_DEBUG=false`, `php artisan config:cache route:cache`, OPcache) — only those numbers are quotable.
2. Make audit logging asynchronous and skip it for `GET`/`/health`; watch deadlocks disappear.
3. Rework the login throttle: `RateLimiter` keyed by IP+email, higher/IP-aware budget (campus NAT).
4. Cache the public explorer endpoints (`/api/public/*` — aggregate, slow-changing) and consider Redis instead of the file cache for counters/sessions under concurrency.
5. Scale PHP workers with expected VU (php-fpm `pm.max_children`), keep an eye on MySQL connections from `/pulse` during the staging run.

## Artifacts

```
load-tests/
  adams-load-test.jmx   # 3 thread groups + disabled setUp/per-user-login alternatives
  users.csv             # admin@example.com (superadmin, skips 2FA -> token directly)
  run-load-test.ps1     # -Profile smoke|load|stress, -TargetHost/-Protocol/-Port for staging
  start-server.ps1      # local 4-worker + proxy target (-Stop to shut down)
  analyze-results.ps1   # per-endpoint percentiles from any .jtl
  lb-proxy.cjs          # round-robin proxy :8000 -> :8001-8004
  results/<profile>-<timestamp>/  # results.jtl + html-report/index.html (git-ignored)
```
