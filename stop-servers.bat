@echo off
REM Stops the BulkWPSender engine and panel (matches their console window titles).
taskkill /FI "WINDOWTITLE eq BulkWPSender Engine*" /T /F >nul 2>&1
taskkill /FI "WINDOWTITLE eq BulkWPSender Panel*" /T /F >nul 2>&1
echo BulkWPSender stopped.
timeout /t 2 /nobreak >nul
