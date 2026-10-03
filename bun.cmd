@echo off
setlocal
rem Portable Bun (bundler, package manager, test runner).
call "%~dp0dev\env.cmd"
"%~dp0.tools\bun\bun.exe" %*
