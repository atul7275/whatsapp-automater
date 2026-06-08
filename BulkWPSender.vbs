' BulkWPSender launcher — runs the tray controller hidden.
' The tray app starts both servers, opens the panel, and lets you stop them.
Set sh  = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
root = fso.GetParentFolderName(WScript.ScriptFullName)
sh.CurrentDirectory = root

sh.Run "powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & root & "\tray.ps1""", 0, False
