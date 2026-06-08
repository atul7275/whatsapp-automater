@echo off
REM ============================================================
REM  BulkWPSender — start both services on Windows
REM  Requirements: Node.js (https://nodejs.org) and PHP in PATH
REM ============================================================
setlocal
cd /d "%~dp0"

echo.
echo  Installing engine dependencies (first run only)...
if not exist "engine\node_modules" (
  pushd engine
  call npm install
  popd
)

echo.
echo  Starting the WhatsApp engine on http://localhost:3000 ...
start "BulkWPSender Engine" cmd /k "cd /d %~dp0engine && npm start"

REM Give the engine a moment to boot
timeout /t 3 /nobreak >nul

echo  Starting the PHP control panel on http://localhost:8080 ...
start "BulkWPSender Panel" cmd /k "php -S localhost:8080 -t %~dp0public"

timeout /t 2 /nobreak >nul
start "" "http://localhost:8080"

echo.
echo  Both services launched. Close their windows to stop.
echo  Control panel:  http://localhost:8080
echo.
endlocal
