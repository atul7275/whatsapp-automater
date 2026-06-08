@echo off
REM ============================================================
REM  BulkWPSender — Troubleshoot / repair (visible)
REM  Checks the install, re-runs setup if needed, and starts the
REM  servers in VISIBLE windows so you can see any error.
REM ============================================================
cd /d "%~dp0"
title BulkWPSender Troubleshoot
echo.
echo ===============================================
echo   BulkWPSender - Troubleshoot
echo ===============================================
echo.

set "OK=1"
if exist "%~dp0runtime\node\node.exe" (echo [ OK ] Node runtime found) else (echo [MISS] Node runtime  ^(runtime\node\node.exe^) & set "OK=0")
if exist "%~dp0runtime\php\php.exe"   (echo [ OK ] PHP runtime found)  else (echo [MISS] PHP runtime   ^(runtime\php\php.exe^)  & set "OK=0")
if exist "%~dp0engine\node_modules"   (echo [ OK ] App dependencies found) else (echo [MISS] App dependencies ^(engine\node_modules^) & set "OK=0")

echo.
if "%OK%"=="0" (
  echo Some components are missing. Running setup now...
  echo This downloads Node.js, PHP and dependencies - please wait.
  echo.
  powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1" -NoShortcut
  echo.
  if errorlevel 1 (
    echo Setup FAILED. Check your internet connection / proxy / antivirus and run this again.
    pause
    exit /b 1
  )
)

echo.
echo Starting servers in visible windows. Watch for errors below.
echo Close those two windows to stop the app.
echo.
start "BulkWPSender Engine (debug)" "%~dp0runtime\node\node.exe" "%~dp0engine\index.js"
timeout /t 3 /nobreak >nul
start "BulkWPSender Panel (debug)" "%~dp0runtime\php\php.exe" -c "%~dp0runtime\php\php.ini" -S 127.0.0.1:8080 -t "%~dp0public"
timeout /t 3 /nobreak >nul
start "" "http://127.0.0.1:8080"

echo.
echo If the page opened at http://127.0.0.1:8080, it is working.
echo If a server window showed an error, copy it and send it for help.
echo.
echo Logs (if any):  %~dp0data\engine.log
echo.
pause
