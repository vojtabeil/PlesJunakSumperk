<#
.SYNOPSIS
    Recreates the local database (deletes data!). Safe to run repeatedly.

.PARAMETER Import
    Imports the given SQL dump (e.g. a phpMyAdmin export from Lebeda) instead of schema.sql + seed.sql.

.PARAMETER NoSeed
    Creates empty tables only, without test data.

.PARAMETER Clean
    Deletes the whole MariaDB data directory (all databases and users) and initializes it again.

.EXAMPLE
    .\init-db.ps1
    .\init-db.ps1 -Import C:\Downloads\ples.sql
    .\init-db.ps1 -Clean
#>
param(
    [string]$Import,
    [switch]$NoSeed,
    [switch]$Clean
)

. "$PSScriptRoot\_common.ps1"

Assert-Installed
if ($Import -and -not (Test-Path $Import)) { throw "File $Import does not exist." }

Write-MyIni
$wasRunning = Test-DbRunning
if ($Clean) {
    Stop-Db
    Write-Step 'Deleting MariaDB data directory'
    if (Test-Path $DbDataDir) { Remove-Item $DbDataDir -Recurse -Force }
}

$started = Start-Db
try {
    Reset-AppDatabase -Import $Import -NoSeed:$NoSeed
} finally {
    # Leave the server in the state we found it.
    if ($started -and -not $wasRunning) { Stop-Db }
}
