$ErrorActionPreference = "Stop"

$phpPath = (Get-Command php -ErrorAction Stop).Source
$phpDirectory = Split-Path -Parent $phpPath
$xamppRoot = Split-Path -Parent $phpDirectory
$opensslCandidates = @(
    (Join-Path $xamppRoot "apache\conf\openssl.cnf"),
    (Join-Path $phpDirectory "extras\ssl\openssl.cnf")
)
$opensslConfig = $opensslCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if ($opensslConfig) {
    $env:OPENSSL_CONF = $opensslConfig
}

& $phpPath (Join-Path $PSScriptRoot "setup-vapid.php")
exit $LASTEXITCODE
