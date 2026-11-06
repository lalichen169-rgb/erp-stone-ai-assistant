@echo off
setlocal EnableExtensions
REM Phase 10.1 — run npm with NVM's user-scoped Node 22.x (no admin, no global npm).
REM Usage from repo root: scripts\npm-user.cmd install
REM Adjust NVM_NODE_VER if you use a different NVM folder name under %%USERPROFILE%%\nvm\

set "NVM_NODE_VER=v22.23.1"
set "NODE_HOME=%USERPROFILE%\nvm\%NVM_NODE_VER%"
if not exist "%NODE_HOME%\node.exe" (
    echo ERROR: Node not found at %NODE_HOME%
    echo Install Node %NVM_NODE_VER% via NVM or extract portable Node under tools\node\ and update PATH manually.
    exit /b 1
)

set "PROJECT_ROOT=%~dp0.."
for %%I in ("%PROJECT_ROOT%") do set "PROJECT_ROOT=%%~fI"
set "PATH=%NODE_HOME%;%PROJECT_ROOT%\node_modules\.bin;%PATH%"

call "%NODE_HOME%\npm.cmd" %*
