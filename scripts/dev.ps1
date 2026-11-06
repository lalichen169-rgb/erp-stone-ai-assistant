# StoneERP-AI-Assistant — project-local development helper
# Runs PHP and Composer via tools/php and tools/composer only.
# Does not modify machine or user PATH permanently.
#
# If you see "running scripts is disabled on this system", either:
#   .\scripts\dev.cmd php -v
#   .\scripts\dev.cmd composer --version
# or one-shot (no persistent policy change):
#   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev.ps1 php -v

#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$PhpRoot = Join-Path $ProjectRoot 'tools\php'
$PhpExe = Join-Path $PhpRoot 'php.exe'
$ComposerPhar = Join-Path $ProjectRoot 'tools\composer\composer.phar'
$ComposerHome = Join-Path $ProjectRoot 'tools\composer\home'
$ComposerCache = Join-Path $ProjectRoot 'tools\composer\cache'

if (-not (Test-Path -LiteralPath $PhpExe)) {
    Write-Error "Project-local PHP not found at: $PhpExe. Run Phase 1 PHP setup first."
}

# Process-local only: PHP DLL path, php.ini, and isolated Composer state (not the global Composer home).
$env:PATH = "$PhpRoot;$env:PATH"
$env:PHPRC = $PhpRoot
$null = New-Item -ItemType Directory -Force -Path $ComposerHome, $ComposerCache
$env:COMPOSER_HOME = $ComposerHome
$env:COMPOSER_CACHE_DIR = $ComposerCache

if ($args.Count -eq 0) {
    Write-Host @"
Usage:
  .\scripts\dev.ps1 php -v
  .\scripts\dev.ps1 php -m
  .\scripts\dev.ps1 composer --version
  .\scripts\dev.ps1 composer diagnose
"@
    exit 1
}

switch ($args[0]) {
    'php' {
        if ($args.Count -eq 1) {
            & $PhpExe
        } else {
            & $PhpExe @($args[1..($args.Count - 1)])
        }
    }
    'composer' {
        $composerArgs = @()
        if ($args.Count -gt 1) {
            $composerArgs = @($args[1..($args.Count - 1)])
        }
        if (Test-Path -LiteralPath $ComposerPhar) {
            & $PhpExe $ComposerPhar @composerArgs
        } else {
            $globalComposer = Get-Command composer -ErrorAction SilentlyContinue
            if ($null -ne $globalComposer) {
                & composer @composerArgs
            } else {
                Write-Error @"
Composer not found. Either:
  - Download https://getcomposer.org/download/ and save composer.phar to:
    $ComposerPhar
  - Or install Composer globally (PATH) per https://getcomposer.org/doc/00-intro.md
See README section 'Obtaining Composer'.
"@
            }
        }
    }
    default {
        Write-Error "Unsupported command '$($args[0])'. Use 'php' or 'composer'."
    }
}

exit $LASTEXITCODE
