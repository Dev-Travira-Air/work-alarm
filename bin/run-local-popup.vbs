Option Explicit

Dim fso, shell, binDirectory, projectRoot, xamppRoot, phpPath, launcherPath, command
Set fso = CreateObject("Scripting.FileSystemObject")
Set shell = CreateObject("WScript.Shell")

binDirectory = fso.GetParentFolderName(WScript.ScriptFullName)
projectRoot = fso.GetParentFolderName(binDirectory)
xamppRoot = fso.GetParentFolderName(fso.GetParentFolderName(projectRoot))
phpPath = fso.BuildPath(xamppRoot, "php\php.exe")
launcherPath = fso.BuildPath(binDirectory, "launch-break.php")

If Not fso.FileExists(phpPath) Or Not fso.FileExists(launcherPath) Then
    WScript.Quit 2
End If

command = Quote(phpPath) & " " & Quote(launcherPath)
WScript.Quit shell.Run(command, 0, True)

Function Quote(value)
    Quote = Chr(34) & value & Chr(34)
End Function
