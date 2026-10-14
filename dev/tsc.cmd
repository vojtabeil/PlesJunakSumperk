@echo off
setlocal
rem Portable TypeScript 7 compiler (type checking).
call "%~dp0env.cmd"
"%~dp0..\.tools\typescript\lib\tsc.exe" %*
