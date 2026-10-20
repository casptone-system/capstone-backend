<#
.SYNOPSIS
  Per-endpoint stats (n, err%, avg, p50/p90/p95/p99, max) from a JMeter .jtl.
.EXAMPLE
  .\load-tests\analyze-results.ps1 -Jtl .\load-tests\results\load-20261021-000000\results.jtl
  .\load-tests\analyze-results.ps1   # newest .jtl under results\
#>
param([string]$Jtl)

if (-not $Jtl) {
    $Jtl = Get-ChildItem (Join-Path $PSScriptRoot 'results\*\results.jtl') |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1 -ExpandProperty FullName
}
Write-Host "Analyzing $Jtl`n"
$rows = Import-Csv $Jtl

function Get-Pct($sorted, [double]$p) {
    if (-not $sorted -or $sorted.Count -eq 0) { return 0 }
    return $sorted[[math]::Min($sorted.Count - 1, [int][math]::Ceiling($p * $sorted.Count) - 1)]
}

$elapsedSec = if ($rows.Count -gt 1) {
    ([long]($rows | Measure-Object timeStamp -Maximum).Maximum - [long]($rows | Measure-Object timeStamp -Minimum).Minimum) / 1000
} else { 0 }

$rows | Group-Object label | ForEach-Object {
    $g = $_.Group
    $times = $g | ForEach-Object { [int]$_.elapsed } | Sort-Object
    $errs = ($g | Where-Object success -eq 'false').Count
    $codes = ($g | Group-Object responseCode | ForEach-Object { "$($_.Name):$($_.Count)" }) -join ' '
    [pscustomobject]@{
        Endpoint    = $_.Name
        N           = $g.Count
        'Err%'      = [math]::Round(100 * $errs / $g.Count, 2)
        AvgMs       = [math]::Round(($times | Measure-Object -Average).Average)
        P50Ms       = Get-Pct $times 0.50
        P90Ms       = Get-Pct $times 0.90
        P95Ms       = Get-Pct $times 0.95
        P99Ms       = Get-Pct $times 0.99
        MaxMs       = ($times | Measure-Object -Maximum).Maximum
        Codes       = $codes
    }
} | Sort-Object P95Ms -Descending | Format-Table -AutoSize

$all = $rows | ForEach-Object { [int]$_.elapsed } | Sort-Object
$totalErr = ($rows | Where-Object success -eq 'false').Count
Write-Host ("TOTAL  n={0}  err={1}%  avg={2}ms  p95={3}ms  max={4}ms  duration={5}s  throughput={6}/s" -f `
    $rows.Count, [math]::Round(100 * $totalErr / $rows.Count, 2), `
    [math]::Round(($all | Measure-Object -Average).Average), (Get-Pct $all 0.95), `
    ($all | Measure-Object -Maximum).Maximum, [math]::Round($elapsedSec), `
    $(if ($elapsedSec) { [math]::Round($rows.Count / $elapsedSec, 2) } else { '-' }))
