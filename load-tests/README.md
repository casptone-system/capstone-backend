# ADAMS — JMeter Load Testing

Load tests for the ADAMS accreditation API (Laravel 12, `routes/api.php`).
Apache **JMeter 5.6.3** (installed at `%USERPROFILE%\apache-jmeter-5.6.3`), non-GUI mode.

## What's covered — `adams-load-test.jmx`

| Thread group | Samplers | Auth |
|---|---|---|
| 1 - Public endpoints | `GET /api/health`, `/api/public/analytics`, `/programs`, `/departments`, `/accreditation-areas`, `/cycles` | none |
| 2 - Authenticated reads | login once/user, then `GET /api/me`, `/dashboard`, `/colleges`, `/programs`, `/documents`, `/accreditation-areas`, `/accreditation-cycles/dashboard`, `/notifications/unread-count`, `/users`, `/reports`, `/instrument-templates` | Sanctum Bearer, token extracted from `$.data.token` |
| 3 - Login stress | `POST /api/login` every iteration (bcrypt + token issue) | — |

Every sampler asserts HTTP 200. Credentials come from `users.csv`
(`admin@example.com` — superadmin skips 2FA, see `AuthController::requiresTwoFactor()`,
so login returns a token directly; other demo roles would stop at the OTP step).

> Excluded on purpose: `/api/qa/dashboard` returns **403 for superadmin**
> (strict per-role RBAC), and write/upload endpoints (POST documents, chunked
> uploads) — load-testing those creates real records/files; add a separate
> plan with disposable data if you need write-path numbers.

## Run it

```powershell
# 1. Local target: 4 php -S workers (8001-8004) + Node round-robin proxy on :8000
#    (Windows php -S is single-threaded per process; one `artisan serve` would
#     serialize every request and measure only itself)
.\load-tests\start-server.ps1            # -Stop to shut down

# 2. Profiles: smoke = 12 VU | load = 55 VU | stress = 110 VU (concurrent, all groups)
.\load-tests\run-load-test.ps1 -Profile smoke
.\load-tests\run-load-test.ps1 -Profile load
.\load-tests\run-load-test.ps1 -Profile stress

# Against staging / production-like hosting (php-fpm + Apache/Nginx) — preferred
# for numbers you would quote in the thesis/paper:
.\load-tests\run-load-test.ps1 -Profile load -TargetHost <host> -Protocol https -Port 443
```

Open in JMeter GUI instead: `& "$env:USERPROFILE\apache-jmeter-5.6.3\bin\jmeter.bat" -t .\load-tests\adams-load-test.jmx`
(tip: never run the actual load from the GUI — use it only to edit the plan).

## Output & pass criteria

Each run writes `results/<profile>-<timestamp>/` with `results.jtl`,
`jmeter.log`, and a self-contained HTML dashboard (`html-report/index.html`).

**PASS** = error rate < 1% **and** p95 (non-login samplers) < 2,000 ms.
The runner exits `2` on FAIL so it can gate CI.

Key properties (override with `-J<name>=<value>` or edit the profile table in
`run-load-test.ps1`): `host`, `port`, `protocol`, `think.ms` (200 ms default),
`public|auth|login .threads/.ramp/.loops`, `users.csv`.

## Interpreting local results

Local numbers are a **lower bound**: laptop CPU, MySQL on the same box,
file cache/session drivers, `APP_DEBUG=true`, no OPcache tuning, and 4
single-threaded PHP workers. Expect staging (Render/Railway, php-fpm) to differ —
re-run there before drawing conclusions about production capacity. Watch during
a run: PHP worker CPU, MySQL `Threads_connected`/slow queries, and Laravel's
`/pulse` (superadmin) if enabled.
