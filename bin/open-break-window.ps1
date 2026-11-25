param(
    [Parameter(Mandatory = $true)][string]$ChromePath,
    [Parameter(Mandatory = $true)][string]$ChromeProfile,
    [Parameter(Mandatory = $true)][string]$TargetUrl
)

$ErrorActionPreference = 'Stop'
Add-Type -Namespace Jeda -Name ChromeWindow -MemberDefinition @'
    [System.Runtime.InteropServices.DllImport("user32.dll", CharSet = System.Runtime.InteropServices.CharSet.Unicode)]
    public static extern System.IntPtr FindWindowEx(System.IntPtr parent, System.IntPtr after, string className, string title);

    [System.Runtime.InteropServices.DllImport("user32.dll")]
    public static extern bool ShowWindowAsync(System.IntPtr window, int command);
'@

$windowTitle = 'Jeda - Saatnya istirahat'
$windowClass = 'Chrome_WidgetWin_1'
$existingWindows = @{}
$window = [IntPtr]::Zero
while (($window = [Jeda.ChromeWindow]::FindWindowEx([IntPtr]::Zero, $window, $windowClass, $windowTitle)) -ne [IntPtr]::Zero) {
    $existingWindows[$window] = $true
}

Start-Process -FilePath $ChromePath -ArgumentList @(
    ('--profile-directory="{0}"' -f $ChromeProfile),
    ('--app="{0}"' -f $TargetUrl),
    '--start-maximized',
    '--no-first-run'
)

$deadline = [DateTime]::UtcNow.AddSeconds(10)
do {
    $window = [IntPtr]::Zero
    while (($window = [Jeda.ChromeWindow]::FindWindowEx([IntPtr]::Zero, $window, $windowClass, $windowTitle)) -ne [IntPtr]::Zero) {
        if (!$existingWindows.ContainsKey($window) -and [Jeda.ChromeWindow]::ShowWindowAsync($window, 3)) {
            exit 0
        }
    }
    Start-Sleep -Milliseconds 200
} while ([DateTime]::UtcNow -lt $deadline)

# Chrome was started, so do not launch another window on the next scheduler tick.
Write-Output 'WINDOW_NOT_MAXIMIZED'
