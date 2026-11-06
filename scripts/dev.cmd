@echo off
setlocal EnableExtensions
REM StoneERP project-local PHP and Composer

set "PROJECT_ROOT=%~dp0.."
for %%I in ("%PROJECT_ROOT%") do set "PROJECT_ROOT=%%~fI"
set "PHP_ROOT=%PROJECT_ROOT%\tools\php"
set "PHP_EXE=%PHP_ROOT%\php.exe"
set "COMPOSER_PHAR=%PROJECT_ROOT%\tools\composer\composer.phar"
set "COMPOSER_HOME=%PROJECT_ROOT%\tools\composer\home"
set "COMPOSER_CACHE_DIR=%PROJECT_ROOT%\tools\composer\cache"

if not exist "%PHP_EXE%" (
    echo Project-local PHP not found at: %PHP_EXE%
    exit /b 1
)

if not exist "%COMPOSER_HOME%" mkdir "%COMPOSER_HOME%"
if not exist "%COMPOSER_CACHE_DIR%" mkdir "%COMPOSER_CACHE_DIR%"
set "PATH=%PHP_ROOT%;%PATH%"
set "PHPRC=%PHP_ROOT%"

if "%~1"=="" (
    echo Usage:
    echo   scripts\dev.cmd php -v
    echo   scripts\dev.cmd composer --version
    exit /b 1
)

if /I "%~1"=="php" goto run_php
if /I "%~1"=="composer" goto run_composer
echo Unsupported command: %~1
echo Use: scripts\dev.cmd php ^| composer [arguments...]
exit /b 1

:run_php
shift
"%PHP_EXE%" %1 %2 %3 %4 %5 %6 %7 %8 %9
exit /b %ERRORLEVEL%

:run_composer
shift
if exist "%COMPOSER_PHAR%" (
    "%PHP_EXE%" "%COMPOSER_PHAR%" %1 %2 %3 %4 %5 %6 %7 %8 %9
    exit /b %ERRORLEVEL%
)
where composer >nul 2>&1
if %ERRORLEVEL% equ 0 (
    composer %1 %2 %3 %4 %5 %6 %7 %8 %9
    exit /b %ERRORLEVEL%
)
echo Composer not found. Download composer.phar to:
echo   %COMPOSER_PHAR%
echo Or install Composer globally ^(see README — Obtaining Composer^).
exit /b 1
