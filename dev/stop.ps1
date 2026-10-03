<#
.SYNOPSIS
    Stops the PHP server and shuts MariaDB down cleanly.
#>

. "$PSScriptRoot\_common.ps1"

$web = Get-PidProcess $WebPidFile 'php'
if ($web) {
    Write-Step 'Stopping PHP server'
    Stop-Process -Id $web.Id -Force
    Remove-Item $WebPidFile -Force -ErrorAction SilentlyContinue
    Write-Ok 'PHP server stopped'
}

Stop-Watchers
Stop-Mailpit

if (Test-Path $MariaAdmin) {
    Write-MyIni
    Stop-Db
}
