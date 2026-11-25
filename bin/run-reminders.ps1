param(
    [string]$PhpPath,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]]$PhpArguments
)

$ErrorActionPreference = "Stop"

if (-not $PhpPath) {
    $PhpPath = (Get-Command php -ErrorAction Stop).Source
}
$phpDirectory = Split-Path -Parent $PhpPath
$xamppRoot = Split-Path -Parent $phpDirectory
$opensslCandidates = @(
    (Join-Path $xamppRoot "apache\conf\openssl.cnf"),
    (Join-Path $phpDirectory "extras\ssl\openssl.cnf")
)
$opensslConfig = $opensslCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if ($opensslConfig) {
    $env:OPENSSL_CONF = $opensslConfig
}

$senderPath = Join-Path $PSScriptRoot "send-reminders.php"
& $PhpPath $senderPath @PhpArguments
exit $LASTEXITCODE
