@echo off
rem Stops the database, Mailpit and the web server of the portable ball site.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\run.ps1" stop
if errorlevel 1 pause
