@echo off
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0dev\release.ps1" %*
if errorlevel 1 pause
