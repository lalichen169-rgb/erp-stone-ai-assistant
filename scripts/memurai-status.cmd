@echo off
setlocal EnableExtensions
REM Status for project-local Memurai on 127.0.0.1:6379 (no secrets displayed).

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0memurai.ps1" status
exit /b %ERRORLEVEL%
