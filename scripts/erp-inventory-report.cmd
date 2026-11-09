@echo off
setlocal EnableExtensions
REM Full ERP inventory matrix (products x warehouses) + supplier / PO line summary.

set "PROJECT_ROOT=%~dp0.."
for %%I in ("%PROJECT_ROOT%") do set "PROJECT_ROOT=%%~fI"
cd /d "%PROJECT_ROOT%"

set "PHP_EXE=%PROJECT_ROOT%\tools\php\php.exe"
if not exist "%PHP_EXE%" (
    echo Project-local PHP not found at: %PHP_EXE%
    exit /b 1
)

"%PHP_EXE%" "%PROJECT_ROOT%\tools\erp-inventory-report.php"
exit /b %ERRORLEVEL%
