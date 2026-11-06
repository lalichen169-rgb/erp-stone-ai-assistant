@echo off
setlocal EnableExtensions
REM Status for project-local MySQL on port 3307 (no credentials displayed).

set "PROJECT_ROOT=%~dp0.."
for %%I in ("%PROJECT_ROOT%") do set "PROJECT_ROOT=%%~fI"
set "MYSQL_HOME=%PROJECT_ROOT%\tools\mysql"
set "MYSQL=%MYSQL_HOME%\server\bin\mysql.exe"
set "MYINI=%MYSQL_HOME%\my.ini"

echo === Port 3307 ===
netstat -an | findstr /R /C:":3307 .*LISTENING"
if errorlevel 1 (
    echo State: NOT LISTENING
    exit /b 1
)
echo State: LISTENING

if exist "%MYSQL%" (
    echo === Server version (requires root password when prompted) ===
    "%MYSQL%" --defaults-file="%MYINI%" --protocol=tcp -h 127.0.0.1 -P 3307 -u root -p -e "SELECT VERSION() AS mysql_version;"
) else (
    echo mysql.exe not found at %MYSQL%
    exit /b 1
)
exit /b 0
