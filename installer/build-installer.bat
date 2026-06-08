@echo off
REM Builds BulkWPSender-Setup.exe from bulkwpsender.iss using Inno Setup 6.
cd /d "%~dp0"
title Build BulkWPSender installer

set "ISCC=iscc"
where iscc >nul 2>&1
if errorlevel 1 set "ISCC=%ProgramFiles(x86)%\Inno Setup 6\ISCC.exe"

if not exist "%ISCC%" (
  if "%ISCC%"=="iscc" goto :noiscc
  if not exist "%ISCC%" goto :noiscc
)

echo Compiling installer...
"%ISCC%" bulkwpsender.iss
if errorlevel 1 ( echo Build failed. & pause & exit /b 1 )

echo.
echo Done. Installer is at:  installer\output\BulkWPSender-Setup.exe
pause
exit /b 0

:noiscc
echo.
echo Inno Setup was not found.
echo  1. Install it from https://jrsoftware.org/isdl.php
echo  2. Re-run this script, OR open bulkwpsender.iss in the Inno Setup
echo     Compiler and press F9 to build.
echo.
pause
exit /b 1
