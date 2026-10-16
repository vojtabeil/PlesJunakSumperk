@echo off
rem Starts the portable ball site for the local network too (phones, other computers).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\run.ps1" start -Lan %*
pause
