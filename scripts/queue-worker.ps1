# StoneERP — Laravel Redis queue worker for async Ollama assistant jobs.
# Uses project-local PHP (tools/php). Does not modify system PATH.
#
# Usage:
#   .\scripts\queue-worker.ps1
#   .\scripts\queue-worker.cmd
#   .\scripts\queue-worker.ps1 -DryRun

#Requires -Version 5.1
param(
    [switch]$DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$PhpRoot = Join-Path $ProjectRoot 'tools\php'
$PhpExe = Join-Path $PhpRoot 'php.exe'
$Artisan = Join-Path $ProjectRoot 'artisan'

if (-not (Test-Path -LiteralPath $PhpExe)) {
    Write-Error "Project-local PHP not found at: $PhpExe. Run Phase 1 PHP setup first."
}

if (-not (Test-Path -LiteralPath $Artisan)) {
    Write-Error "Laravel artisan not found at: $Artisan"
}

$env:PATH = "$PhpRoot;$env:PATH"
$env:PHPRC = $PhpRoot

function Get-LaravelConfigValue {
    param([Parameter(Mandatory = $true)][string]$Key)

    Push-Location -LiteralPath $ProjectRoot
    try {
        $raw = & $PhpExe $Artisan config:show $Key --no-ansi 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "Could not read config key '$Key'. Ensure .env exists and 'composer install' has been run."
        }
        $text = ($raw | Out-String).Trim()
        if ($text -eq '') {
            throw "Empty config output for '$Key'."
        }
        $line = ($text -split "`r?`n" | Where-Object { $_.Trim() -ne '' } | Select-Object -Last 1)
        $trimmed = $line.Trim()
        $parts = $trimmed -split '\s+'
        return $parts[-1]
    } finally {
        Pop-Location
    }
}

$connection = Get-LaravelConfigValue -Key 'ai.assistant.async.queue_connection'
$queue = Get-LaravelConfigValue -Key 'ai.assistant.async.queue'
$timeoutRaw = Get-LaravelConfigValue -Key 'ai.assistant.async.job_timeout_seconds'
$timeout = [int]$timeoutRaw
if ($timeout -lt 60) {
    $timeout = 900
}

$workerArgv = @(
    '-d',
    'max_execution_time=0',
    $Artisan,
    'queue:work',
    $connection,
    "--queue=$queue",
    '--sleep=3',
    '--tries=1',
    "--timeout=$timeout",
    '--max-time=3600'
)

Write-Host "StoneERP assistant queue worker"
Write-Host "  project    : $ProjectRoot"
Write-Host "  connection : $connection"
Write-Host "  queue      : $queue"
Write-Host "  job timeout: ${timeout}s (worker --timeout matches configured assistant job timeout)"
Write-Host "  php limit  : unlimited (CLI -d max_execution_time=0; per-job cap inside ProcessOllamaAssistantRun)"
Write-Host "  tries      : 1"
Write-Host "Press Ctrl+C to stop."

if ($DryRun) {
    $quoted = $workerArgv | ForEach-Object {
        if ($_ -match '\s') { '"' + $_ + '"' } else { $_ }
    }
    Write-Host "[DryRun] Would execute: $PhpExe $($quoted -join ' ')"
    exit 0
}

Set-Location -LiteralPath $ProjectRoot
& $PhpExe @workerArgv
exit $LASTEXITCODE
