@echo off
setlocal EnableExtensions
REM Start project-local Memurai (Redis-compatible) on 127.0.0.1:6379 only.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0memurai.ps1" start
exit /b %ERRORLEVEL%
