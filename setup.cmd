@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0dev\setup.ps1" %*
if errorlevel 1 pause
