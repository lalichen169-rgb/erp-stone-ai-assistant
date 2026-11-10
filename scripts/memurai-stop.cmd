@echo off
setlocal EnableExtensions
REM Stop only tools\memurai\memurai.exe for this project (never by port alone).

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0memurai.ps1" stop
exit /b %ERRORLEVEL%
