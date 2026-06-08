@echo off
REM ============================================================
REM  BulkWPSender - Troubleshoot / repair (visible)
REM  Verifies the install, repairs broken dependencies, and runs
REM  the servers in VISIBLE windows so any error is on screen.
REM ============================================================
cd /d "%~dp0"
title BulkWPSender Troubleshoot
echo.
echo ===============================================
echo   BulkWPSender - Troubleshoot
echo ===============================================
echo.

set "NODE=%~dp0runtime\node\node.exe"
set "NPM=%~dp0runtime\node\npm.cmd"
set "PHP=%~dp0runtime\php\php.exe"

set "OK=1"
if exist "%NODE%" (echo [ OK ] Node runtime found) else (echo [MISS] Node runtime  & set "OK=0")
if exist "%PHP%"  (echo [ OK ] PHP runtime found)  else (echo [MISS] PHP runtime   & set "OK=0")
if exist "%~dp0engine\node_modules" (echo [ OK ] node_modules folder present) else (echo [MISS] node_modules & set "OK=0")

if "%OK%"=="0" (
  echo.
  echo Missing components - running full setup...
  powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1" -NoShortcut
  if errorlevel 1 ( echo Setup FAILED - check internet/proxy/antivirus. & pause & exit /b 1 )
)

echo.
echo Checking that the engine's modules actually load...
"%NODE%" -e "require('better-sqlite3');require('whatsapp-web.js');require('express');console.log('modules-ok')" 2>"%~dp0data\modcheck.log"
if errorlevel 1 (
  echo [REPAIR] A dependency is broken or incomplete. Reinstalling...
  type "%~dp0data\modcheck.log"
  if exist "%~dp0engine\node_modules" rmdir /s /q "%~dp0engine\node_modules"
  pushd "%~dp0engine"
  call "%NPM%" install --no-audit --no-fund
  popd
  echo Re-checking...
  "%NODE%" -e "require('better-sqlite3');require('whatsapp-web.js');console.log('modules-ok')"
  if errorlevel 1 ( echo Still failing - please send me the messages above. & pause & exit /b 1 )
) else (
  echo [ OK ] Engine modules load fine.
)

echo.
echo Starting servers in VISIBLE windows. Watch for errors.
echo Close those windows to stop the app.
echo.
start "BulkWPSender Engine (debug)" "%NODE%" "%~dp0engine\index.js"
timeout /t 4 /nobreak >nul
start "BulkWPSender Panel (debug)" "%PHP%" -c "%~dp0runtime\php\php.ini" -S 127.0.0.1:8080 -t "%~dp0public"
timeout /t 3 /nobreak >nul
start "" "http://127.0.0.1:8080"

echo.
echo If the page works now, you're set - use the normal shortcut next time.
echo If the Engine window showed an error, copy it (and data\engine.log) and send it.
echo.
pause
