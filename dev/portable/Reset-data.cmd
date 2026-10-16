@echo off
rem Deletes all data (reservations, payments, e-mails, logs) and starts again with the sample data.
choice /M "Delete all data and start again with the sample data"
if errorlevel 2 exit /b
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\run.ps1" reset %*
pause
