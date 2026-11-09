# Run PHPUnit via project-local PHP. Optional: pass paths/filters as extra args.
# Example: .\scripts\test.ps1 --testsuite=Unit
#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Push-Location $ProjectRoot
try {
  $vendorPhpUnit = Join-Path $ProjectRoot 'vendor\bin\phpunit'
  if (-not (Test-Path -LiteralPath $vendorPhpUnit)) {
    Write-Error "PHPUnit not found at $vendorPhpUnit. Run composer install first."
  }
  $phpArgs = @($vendorPhpUnit)
  if ($args.Count -gt 0) {
    $phpArgs += $args
  }
  & (Join-Path $PSScriptRoot 'dev.ps1') php @phpArgs
  exit $LASTEXITCODE
} finally {
  Pop-Location
}
