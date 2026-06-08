@echo off
REM Starts the Node engine and the PHP control panel using the bundled runtimes.
setlocal
set "ROOT=%~dp0"

start "BulkWPSender Engine" /min "%ROOT%runtime\node\node.exe" "%ROOT%engine\index.js"
timeout /t 3 /nobreak >nul
start "BulkWPSender Panel" /min "%ROOT%runtime\php\php.exe" -c "%ROOT%runtime\php\php.ini" -S 127.0.0.1:8080 -t "%ROOT%public"

endlocal
