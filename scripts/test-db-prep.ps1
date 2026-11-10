# Prepare stone_erp_ai_test for PHPUnit (migrate:fresh). Never targets stone_erp_ai.
#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $ProjectRoot
try {
    $show = & (Join-Path $PSScriptRoot 'dev.ps1') php artisan db:show --env=testing 2>&1 | Out-String
    if ($show -notmatch 'stone_erp_ai_test') {
        Write-Error "Refusing migrate:fresh: effective testing database is not stone_erp_ai_test. db:show output:`n$show"
    }
    Write-Host "Confirmed test database name stone_erp_ai_test via db:show."
    & (Join-Path $PSScriptRoot 'dev.ps1') php artisan migrate:fresh --env=testing --force --no-interaction
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
    Write-Host "Test database schema reset complete."
} finally {
    Pop-Location
}
