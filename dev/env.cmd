@rem Environment for the portable tools: keeps every cache and temp file inside the project.
@rem Single source of truth - dev/_common.ps1 parses the `set "NAME=VALUE"` lines below,
@rem so keep exactly that form and use only %ROOT% as a variable.
@set "ROOT=%~dp0.."
@set "TEMP=%ROOT%\.devdata\tmp"
@set "TMP=%ROOT%\.devdata\tmp"
@set "COMPOSER_HOME=%ROOT%\.devdata\composer"
@set "COMPOSER_CACHE_DIR=%ROOT%\.devdata\composer\cache"
@set "BUN_INSTALL=%ROOT%\.devdata\bun"
@set "BUN_INSTALL_CACHE_DIR=%ROOT%\.devdata\bun\install-cache"
@set "BUN_RUNTIME_TRANSPILER_CACHE_PATH=%ROOT%\.devdata\bun\transpiler-cache"
@set "DO_NOT_TRACK=1"
@if not exist "%ROOT%\.devdata\tmp" mkdir "%ROOT%\.devdata\tmp"
