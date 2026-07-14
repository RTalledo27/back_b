[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$runner = Join-Path $PSScriptRoot 'test-backend.ps1'
$failures = [System.Collections.Generic.List[string]]::new()

function Invoke-GuardCase {
    param(
        [Parameter(Mandatory)] [string] $Name,
        [string] $DatabaseName,
        [Parameter(Mandatory)] [bool] $ShouldPass
    )

    $output = & powershell -NoProfile -ExecutionPolicy Bypass -File $runner -ValidateOnly -DatabaseName $DatabaseName 2>&1
    $exitCode = $LASTEXITCODE
    $passed = if ($ShouldPass) { $exitCode -eq 0 } else { $exitCode -ne 0 }

    if (-not $passed) {
        $failures.Add("$Name returned unexpected exit code $exitCode.")
    }
    if (-not $ShouldPass -and ($output -join "`n") -match '\[database\] Creating') {
        $failures.Add("$Name executed a database command after rejection.")
    }

    Write-Host ("[{0}] {1}" -f ($(if ($passed) { 'PASS' } else { 'FAIL' })), $Name)
}

Invoke-GuardCase -Name 'rejects primary database' -DatabaseName 'backend_rifas_app' -ShouldPass $false
Invoke-GuardCase -Name 'rejects postgres' -DatabaseName 'postgres' -ShouldPass $false
Invoke-GuardCase -Name 'rejects defaultdb' -DatabaseName 'defaultdb' -ShouldPass $false
Invoke-GuardCase -Name 'rejects empty name' -DatabaseName '   ' -ShouldPass $false
Invoke-GuardCase -Name 'rejects shared legacy test database' -DatabaseName 'backend_rifas_app_test' -ShouldPass $false
Invoke-GuardCase -Name 'rejects unsafe token characters' -DatabaseName 'backend_rifas_app_test_bad-name' -ShouldPass $false
Invoke-GuardCase -Name 'accepts isolated test database' -DatabaseName 'backend_rifas_app_test_guardcheck' -ShouldPass $true

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host '[PASS] Guard validation completed without Docker or database mutations.'
