@echo off
rem Windows Defender Firewall: lets other devices in the local network (phone, other computer) reach the site.
rem Asks for administrator rights. "Allow-LAN.cmd remove" deletes the rule again.
if /i "%~1"=="remove" (set ACTION=firewall-remove) else (set ACTION=firewall)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\run.ps1" %ACTION%
pause
