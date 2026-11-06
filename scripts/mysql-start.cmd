@echo off
setlocal EnableExtensions
REM Start project-local MySQL 8.4 on 127.0.0.1:3307 only (not XAMPP).

set "PROJECT_ROOT=%~dp0.."
for %%I in ("%PROJECT_ROOT%") do set "PROJECT_ROOT=%%~fI"
set "MYSQL_HOME=%PROJECT_ROOT%\tools\mysql"
set "MYSQLD=%MYSQL_HOME%\server\bin\mysqld.exe"
set "MYINI=%MYSQL_HOME%\my.ini"
set "PIDFILE=%MYSQL_HOME%\data\mysqld.pid"

if not exist "%MYSQLD%" (
    echo Project-local mysqld not found: %MYSQLD%
    exit /b 1
)
if not exist "%MYINI%" (
    echo MySQL config not found: %MYINI%
    exit /b 1
)

netstat -an | findstr /R /C:":3307 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo Project MySQL is already listening on 127.0.0.1:3307.
    exit /b 0
)

start "" /B "%MYSQLD%" --defaults-file="%MYINI%"
ping 127.0.0.1 -n 4 >nul

netstat -an | findstr /R /C:":3307 .*LISTENING" >nul 2>&1
if errorlevel 1 (
    echo MySQL failed to start. Check: %MYSQL_HOME%\logs\error.log
    exit /b 1
)

echo Project-local MySQL started on 127.0.0.1:3307
exit /b 0
