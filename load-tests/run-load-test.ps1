<#
.SYNOPSIS
  Run the ADAMS JMeter load test (non-GUI) and generate an HTML dashboard.
.EXAMPLE
  .\load-tests\run-load-test.ps1 -Profile smoke
  .\load-tests\run-load-test.ps1 -Profile load
  .\load-tests\run-load-test.ps1 -Profile stress
  # Against staging/production-like hosting (recommended for true numbers):
  .\load-tests\run-load-test.ps1 -Profile load -TargetHost my-app.onrender.com -Protocol https -Port 443
.NOTES
  Profiles (threads are VIRTUAL USERS per thread group, run concurrently):
    smoke : public 5 / auth 5 / login 2   - sanity, ~2 min
    load  : public 25 / auth 25 / login 5 - expected peak load
    stress: public 50 / auth 50 / login 10- find the breaking point
  Pass criteria (checked at the end, exit code 2 if violated):
    error % < 1% and p95 < 2000 ms for non-login samplers.
#>
param(
    [ValidateSet('smoke', 'load', 'stress')][string]$Profile = 'smoke',
    [string]$TargetHost = '127.0.0.1', [string]$Protocol = 'http', [int]$Port = 8000,
    [string]$JMeterHome = "$env:USERPROFILE\apache-jmeter-5.6.3"
)

$ErrorActionPreference = 'Stop'
$jmeter = Join-Path $JMeterHome 'bin\jmeter.bat'
if (-not (Test-Path $jmeter)) { throw "JMeter not found at $jmeter - download apache-jmeter-5.6.3 from https://jmeter.apache.org/download_jmeter.cgi" }

$profiles = @{
    smoke  = @{ 'public.threads' = 5;  'public.ramp' = 10; 'public.loops' = 3;  'auth.threads' = 5;  'auth.ramp' = 10; 'auth.loops' = 3;  'login.threads' = 2;  'login.ramp' = 5;  'login.loops' = 2;  'think.ms' = 200 }
    load   = @{ 'public.threads' = 25; 'public.ramp' = 30; 'public.loops' = 10; 'auth.threads' = 25; 'auth.ramp' = 30; 'auth.loops' = 10; 'login.threads' = 5;  'login.ramp' = 10; 'login.loops' = 10; 'think.ms' = 200 }
    stress = @{ 'public.threads' = 50; 'public.ramp' = 60; 'public.loops' = 10; 'auth.threads' = 50; 'auth.ramp' = 60; 'auth.loops' = 10; 'login.threads' = 10; 'login.ramp' = 20; 'login.loops' = 20; 'think.ms' = 100 }
}
$cfg = $profiles[$Profile]
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$outDir = Join-Path $PSScriptRoot "results\$Profile-$stamp"
New-Item -ItemType Directory -Force $outDir | Out-Null
$jtl = Join-Path $outDir 'results.jtl'

# One shared Sanctum token for the Authenticated group (avoids 25 per-thread
# logins tripping the app's 12-logins/min/IP throttle and cascading 401s).
$userLine = (Get-Content (Join-Path $PSScriptRoot 'users.csv') | Select-Object -Skip 1 -First 1) -split ','
$loginBody = @{ email = $userLine[0].Trim(); password = $userLine[1].Trim() } | ConvertTo-Json
try {
    $authToken = (Invoke-RestMethod -Uri "${Protocol}://${TargetHost}:${Port}/api/login" -Method Post -Body $loginBody -ContentType 'application/json' -TimeoutSec 30).data.token
} catch {
    Write-Host "Pre-flight login failed ($($_.Exception.Message)) - if 422, the 12/min/IP login throttle is hot; waiting 61s and retrying once..." -ForegroundColor Yellow
    Start-Sleep 61
    $authToken = (Invoke-RestMethod -Uri "${Protocol}://${TargetHost}:${Port}/api/login" -Method Post -Body $loginBody -ContentType 'application/json' -TimeoutSec 30).data.token
}
if (-not $authToken) { throw 'Could not obtain auth token - authenticated group would only produce 401s. Aborting.' }
Write-Host "Pre-flight login OK (shared token for authenticated thread group)"

# Via -q properties file, NOT -J: Sanctum tokens contain '|' which cmd.exe would pipe-split.
$authProps = Join-Path $outDir 'auth.properties'
Set-Content -Path $authProps -Value "authToken=$authToken" -Encoding Ascii

$jArgs = @('-n', '-t', (Join-Path $PSScriptRoot 'adams-load-test.jmx'), '-q', $authProps,
    "-Jhost=$TargetHost", "-Jprotocol=$Protocol", "-Jport=$Port",
    "-Jusers.csv=$((Join-Path $PSScriptRoot 'users.csv') -replace '\\','/')",
    '-l', $jtl, '-e', '-o', (Join-Path $outDir 'html-report'), '-j', (Join-Path $outDir 'jmeter.log'))
foreach ($k in $cfg.Keys) { $jArgs += "-J$k=$($cfg[$k])" }

Write-Host "== ADAMS JMeter [$Profile] -> ${Protocol}://${TargetHost}:${Port} ==" -ForegroundColor Cyan
& $jmeter @jArgs
$jmeterExit = $LASTEXITCODE
# Revoke the shared test token again
try { Invoke-RestMethod -Uri "${Protocol}://${TargetHost}:${Port}/api/logout" -Method Post -Headers @{ Authorization = "Bearer $authToken" } -TimeoutSec 15 | Out-Null; Write-Host 'Shared test token revoked (logout)' } catch { Write-Host "Token revoke failed: $($_.Exception.Message)" -ForegroundColor Yellow }
Remove-Item $authProps -Force -ErrorAction SilentlyContinue
if ($jmeterExit -ne 0) { throw "JMeter exited $jmeterExit - see $outDir\jmeter.log" }

# ---- Verdict from the .jtl (label, elapsed, success) ----
$rows = Import-Csv $jtl
# Login-stress 422s are the app's 12/min/IP throttle working as designed - reported separately, not as errors.
$core = $rows | Where-Object { $_.label -notmatch 'login \(stress\)' }
$loginRows = $rows | Where-Object { $_.label -match 'login \(stress\)' }
$loginOk = ($loginRows | Where-Object responseCode -eq '200').Count
$loginThrottled = ($loginRows | Where-Object responseCode -eq '422').Count
$fail = ($core | Where-Object success -eq 'false').Count
$errPct = if ($core.Count) { [math]::Round(100 * $fail / $core.Count, 2) } else { 100 }
Write-Host "Login stress: $loginOk x 200 OK, $loginThrottled x 422 throttled (limit 12/min/IP), $(($loginRows | Where-Object { $_.responseCode -notin @('200','422') }).Count) x other"
$sorted = $rows | Where-Object { $_.label -notmatch 'login' } | ForEach-Object { [int]$_.elapsed } | Sort-Object
$p95 = if ($sorted) { $sorted[[math]::Min($sorted.Count - 1, [int][math]::Ceiling(0.95 * $sorted.Count) - 1)] } else { 0 }
Write-Host "`nSamples: $($rows.Count) (core: $($core.Count))  Core errors: $fail ($errPct%)  p95 (non-login): ${p95}ms"
Write-Host "HTML report: $outDir\html-report\index.html"
if ($errPct -ge 1 -or $p95 -ge 2000) { Write-Host 'VERDICT: FAIL (error% >= 1 or p95 >= 2000ms)' -ForegroundColor Red; exit 2 }
Write-Host 'VERDICT: PASS' -ForegroundColor Green
