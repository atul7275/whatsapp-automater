@echo off
REM ============================================================
REM  BulkWPSender — portable installer (no .exe build needed)
REM  Downloads Node.js + PHP, installs dependencies, sets up the
REM  database on first run, then launches the app.
REM ============================================================
cd /d "%~dp0"
title BulkWPSender Setup

echo.
echo  Setting up BulkWPSender.
echo  This downloads Node.js, PHP and app dependencies (incl. Chromium).
echo  It can take several minutes on the first run. Please wait...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup.ps1"
if errorlevel 1 (
  echo.
  echo  Setup FAILED. Check your internet connection and try again.
  pause
  exit /b 1
)

echo.
echo  Launching BulkWPSender...
powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0tray.ps1"
echo.
echo  Done. The app opens at  http://localhost:8080  (tray icon, bottom-right).
echo  If nothing appears, run  Troubleshoot.bat  in this folder.
echo.
pause
