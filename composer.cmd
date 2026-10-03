@echo off
setlocal
rem Portable Composer (runs on the portable PHP).
call "%~dp0dev\env.cmd"
"%~dp0.tools\php\php.exe" -c "%~dp0.tools\php\php.ini" "%~dp0.tools\composer\composer.phar" %*
