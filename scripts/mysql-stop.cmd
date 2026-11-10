@echo off
setlocal EnableExtensions
REM Gracefully stop project-local MySQL on port 3307 only.

set "PROJECT_ROOT=%~dp0.."
for %%I in ("%PROJECT_ROOT%") do set "PROJECT_ROOT=%%~fI"
set "MYSQL_HOME=%PROJECT_ROOT%\tools\mysql"
set "MYSQLADMIN=%MYSQL_HOME%\server\bin\mysqladmin.exe"
set "MYINI=%MYSQL_HOME%\my.ini"

if not exist "%MYSQLADMIN%" (
    echo mysqladmin not found: %MYSQLADMIN%
    exit /b 1
)

netstat -an | findstr /R /C:":3307 .*LISTENING" >nul 2>&1
if errorlevel 1 (
    echo Project MySQL is not listening on port 3307.
    exit /b 0
)

echo Shutting down project MySQL on 127.0.0.1:3307 ...
echo You will be prompted for the MySQL root password.
"%MYSQLADMIN%" --defaults-file="%MYINI%" --protocol=tcp -h 127.0.0.1 -P 3307 -u root -p shutdown
if errorlevel 1 (
    echo Shutdown failed. See %MYSQL_HOME%\logs\error.log
    exit /b 1
)
echo Project-local MySQL stopped.
exit /b 0
