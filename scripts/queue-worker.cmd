@echo off
setlocal EnableExtensions
REM Start Laravel Redis queue worker for async Ollama assistant (project-local PHP).

set "PROJECT_ROOT=%~dp0.."
for %%I in ("%PROJECT_ROOT%") do set "PROJECT_ROOT=%%~fI"
cd /d "%PROJECT_ROOT%"

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0queue-worker.ps1" %*
exit /b %ERRORLEVEL%
