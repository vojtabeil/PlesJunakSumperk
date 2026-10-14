@echo off
setlocal
rem Portable Dart Sass.
call "%~dp0env.cmd"
"%~dp0..\.tools\sass\src\dart.exe" "%~dp0..\.tools\sass\src\sass.snapshot" %*
