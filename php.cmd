@echo off
setlocal
rem Portable PHP with the project php.ini.
call "%~dp0dev\env.cmd"
"%~dp0.tools\php\php.exe" -c "%~dp0.tools\php\php.ini" %*
