<#
PowerShell helper: generate .vscode/sftp.json from a local .env file
Usage:
  1. Copy .env.example -> .env and fill values (DO NOT commit .env).
  2. Run: .\generate-sftp.ps1
#>

$envFile = Join-Path $PSScriptRoot '.env'
if (-not (Test-Path $envFile)) {
    Write-Error "Missing .env file. Create it from .env.example and fill credentials."
    exit 1
}

# Read simple KEY=VALUE pairs (ignores comments and blank lines)
Get-Content $envFile | ForEach-Object {
    if ($_ -match '^\s*([^#=\s]+)\s*=\s*(.*)\s*$') {
        $k = $matches[1]
        $v = $matches[2]
        Set-Item -Path "env:$k" -Value $v
    }
}

# Build hashtable for JSON
$sftp = @{
    name = 'SFTP (local)'
    host = $env:SFTP_HOST
    protocol = $env:SFTP_PROTOCOL
    port = if ($env:SFTP_PORT) { [int]$env:SFTP_PORT } else { if ($env:SFTP_PROTOCOL -eq 'sftp') { 22 } else { 21 } }
    passive = if ($env:SFTP_PASSIVE) { [bool]::Parse($env:SFTP_PASSIVE) } else { $true }
    username = $env:SFTP_USER
}

if ($env:SFTP_PROTOCOL -eq 'sftp' -and $env:SFTP_PRIVATE_KEY) {
    $sftp.privateKeyPath = $env:SFTP_PRIVATE_KEY
    if ($env:SFTP_PASSPHRASE) { $sftp.passphrase = $env:SFTP_PASSPHRASE }
} else {
    $sftp.password = $env:SFTP_PASSWORD
}

if ($env:SFTP_REMOTE_PATH) { $sftp.remotePath = $env:SFTP_REMOTE_PATH }
if ($env:SFTP_CONTEXT) { $sftp.context = $env:SFTP_CONTEXT }
$sftp.uploadOnSave = $false
$sftp.downloadOnOpen = $false
$sftp.syncMode = 'full'
$sftp.ignore = @('**/.vscode/**','**/.git/**','**/.DS_Store')
$sftp.watcher = @{ files = '**/*'; autoUpload = $false; autoDelete = $false }
$sftp.remoteTimeOffsetInHours = 0

# Ensure .vscode dir exists
$dir = Join-Path $PSScriptRoot '.vscode'
if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir | Out-Null }

$outFile = Join-Path $dir 'sftp.json'
$sftp | ConvertTo-Json -Depth 5 | Set-Content -Path $outFile -Encoding UTF8

Write-Output "Wrote local .vscode/sftp.json (DO NOT commit this file)."
Write-Output "Make sure .env is in .gitignore and has restrictive permissions."
