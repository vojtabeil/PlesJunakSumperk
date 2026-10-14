@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0init-db.ps1" %*
if errorlevel 1 pause
