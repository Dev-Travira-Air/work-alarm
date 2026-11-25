$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot
$phpCommand = Get-Command php -ErrorAction Stop
$phpPath = $phpCommand.Source
$phpDirectory = Split-Path -Parent $phpPath
$xamppRoot = Split-Path -Parent $phpDirectory
$htdocsRoot = [System.IO.Path]::GetFullPath((Join-Path $xamppRoot "htdocs")).TrimEnd("\")
if (-not $projectRoot.StartsWith($htdocsRoot + "\", [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "Project harus berada di bawah $htdocsRoot agar URL PWA dapat dibuat otomatis."
}

$desktopPath = [Environment]::GetFolderPath("Desktop")
$shortcutFile = Get-ChildItem $desktopPath -Filter "*.lnk" |
    Where-Object { $_.Name -like "*Jeda*" } |
    Select-Object -First 1
if (-not $shortcutFile) {
    throw "Shortcut PWA Jeda tidak ditemukan di Desktop. Pastikan PWA sudah terpasang, lalu jalankan ulang skrip ini."
}

$shortcut = (New-Object -ComObject WScript.Shell).CreateShortcut($shortcutFile.FullName)
$chromePath = Join-Path (Split-Path -Parent $shortcut.TargetPath) "chrome.exe"
if (-not (Test-Path $chromePath)) {
    throw "Chrome tidak ditemukan di $chromePath."
}
$profileMatch = [regex]::Match($shortcut.Arguments, '--profile-directory=(?:"([^"]+)"|(\S+))')
$chromeProfile = if ($profileMatch.Success) {
    if ($profileMatch.Groups[1].Success) { $profileMatch.Groups[1].Value } else { $profileMatch.Groups[2].Value }
} else {
    "Default"
}

$relativePath = $projectRoot.Substring($htdocsRoot.Length).TrimStart("\").Replace("\", "/")
$appUrl = "http://localhost/$relativePath/"
$storagePath = Join-Path $projectRoot "storage"
if (-not (Test-Path $storagePath)) {
    New-Item -ItemType Directory -Path $storagePath | Out-Null
}
$windowConfig = @{
    chrome_path = $chromePath
    profile = $chromeProfile
    app_url = $appUrl
} | ConvertTo-Json
$utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Join-Path $storagePath "windows-pwa.json"), $windowConfig, $utf8WithoutBom)

$runnerPath = Join-Path $PSScriptRoot "run-local-popup.vbs"
$windowsScriptHost = Join-Path $env:WINDIR "System32\wscript.exe"
$taskName = "Jeda Reminder Popup"
$legacyTaskName = "Jeda Web Push Reminder"

$action = New-ScheduledTaskAction `
    -Execute $windowsScriptHost `
    -Argument ('"{0}"' -f $runnerPath) `
    -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$principal = New-ScheduledTaskPrincipal `
    -UserId ("{0}\{1}" -f $env:USERDOMAIN, $env:USERNAME) `
    -LogonType Interactive `
    -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 2) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description "Membuka popup PWA Jeda sesuai schedule.json tanpa jendela PowerShell." `
    -Force | Out-Null

if (Get-ScheduledTask -TaskName $legacyTaskName -ErrorAction SilentlyContinue) {
    Unregister-ScheduledTask -TaskName $legacyTaskName -Confirm:$false
}

Write-Host "Task '$taskName' terpasang dan akan memeriksa jadwal setiap menit."
Write-Host "Jendela Chrome Jeda dimaksimalkan saat pengingat berbunyi."
Write-Host "PC harus menyala dan akun Windows Anda sedang login."
Write-Host "Jadwal dapat diubah di schedule.json."
