<#
.SYNOPSIS
  Start ADAMS locally for load testing: 4x `php -S` workers (8001-8004)
  behind the Node round-robin proxy on 127.0.0.1:8000 (single origin for JMeter).
  Windows `php -S` is single-threaded per process, hence the workers + proxy.
.EXAMPLE
  .\load-tests\start-server.ps1
  .\load-tests\start-server.ps1 -Stop
#>
param([switch]$Stop, [int]$Workers = 4, [int]$BasePort = 8001, [int]$Port = 8000)

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$router = Join-Path $root 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
$logDir = Join-Path $PSScriptRoot 'results\server-logs'
New-Item -ItemType Directory -Force $logDir | Out-Null

foreach ($p in @($Port) + ($BasePort..($BasePort + $Workers - 1))) {
    Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique |
        ForEach-Object { Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue; Write-Host "killed pid $_ on :$p" }
}
if ($Stop) { return }

for ($i = 0; $i -lt $Workers; $i++) {
    $wp = $BasePort + $i
    # Laravel's server.php router does require getcwd().'/index.php' -> cwd must be public/
    Start-Process php -ArgumentList '-S', "127.0.0.1:$wp", '-t', (Join-Path $root 'public'), $router `
        -WorkingDirectory (Join-Path $root 'public') -WindowStyle Hidden `
        -RedirectStandardOutput "$logDir\php-$wp.out.log" -RedirectStandardError "$logDir\php-$wp.err.log"
    Write-Host "php worker :$wp"
}
$env:PROXY_PORT = "$Port"; $env:BACKEND_PORTS = ($BasePort..($BasePort + $Workers - 1) -join ',')
Start-Process node -ArgumentList (Join-Path $PSScriptRoot 'lb-proxy.cjs') `
    -WorkingDirectory $PSScriptRoot -WindowStyle Hidden `
    -RedirectStandardOutput "$logDir\proxy.out.log" -RedirectStandardError "$logDir\proxy.err.log"

for ($try = 0; $try -lt 20; $try++) {
    Start-Sleep -Milliseconds 750
    try {
        $h = Invoke-RestMethod "http://127.0.0.1:$Port/api/health" -TimeoutSec 5
        if ($h.success) { Write-Host "READY http://127.0.0.1:$Port/api/health -> $($h.message)"; return }
    } catch { }
}
throw "Server did not become ready on :$Port - see $logDir"
