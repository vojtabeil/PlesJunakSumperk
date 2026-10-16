@echo off
rem Starts the portable ball site on this computer only and opens the browser.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\run.ps1" start %*
pause
