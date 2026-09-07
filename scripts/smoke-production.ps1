param(
    [Parameter(Mandatory = $true)]
    [string] $ApiBaseUrl,
    [string] $FrontendUrl = ""
)

$ErrorActionPreference = "Stop"
$api = $ApiBaseUrl.TrimEnd("/")
if ($api.EndsWith("/api")) {
    $origin = $api.Substring(0, $api.Length - 4)
} else {
    $origin = $api
    $api = "$api/api"
}

function Assert-Ok([string] $Name, [int] $Status) {
    if ($Status -lt 200 -or $Status -ge 400) {
        throw "$Name failed with HTTP $Status"
    }
    Write-Host "OK  $Name ($Status)"
}

$up = Invoke-WebRequest -Uri "$origin/up" -UseBasicParsing
Assert-Ok "GET /up" $up.StatusCode

$health = Invoke-WebRequest -Uri "$api/health" -UseBasicParsing
Assert-Ok "GET /api/health" $health.StatusCode

if ($FrontendUrl) {
    $front = Invoke-WebRequest -Uri $FrontendUrl -UseBasicParsing
    Assert-Ok "GET frontend" $front.StatusCode
}

Write-Host "Smoke checks passed. Next: login, upload a document, confirm it in Supabase, then refresh a dashboard route."
