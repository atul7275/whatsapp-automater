; ============================================================================
;  BulkWPSender — Inno Setup script
;  Produces BulkWPSender-Setup.exe. The actual heavy lifting (downloading
;  Node.js + PHP + dependencies) happens in setup.ps1, run at the end of install.
;
;  To build: install Inno Setup 6 (https://jrsoftware.org/isdl.php), then run
;  installer\build-installer.bat  (or open this file and press F9).
; ============================================================================

#define AppName "BulkWPSender"
#define AppVersion "1.9.0"
#define AppPublisher "BulkWPSender"

[Setup]
; Stable AppId so new versions install OVER the existing one (same folder,
; one uninstaller) — this is what keeps the data\ folder across updates.
AppId={{B7C3E2A1-9D54-4F6B-A0E2-1234567890AB}
AppName={#AppName}
AppVersion={#AppVersion}
AppPublisher={#AppPublisher}
VersionInfoVersion={#AppVersion}
; Install per-user into a writable location (the app writes its own data/ DB),
; so no admin rights are needed and runtime writes never get blocked.
PrivilegesRequired=lowest
DefaultDirName={localappdata}\{#AppName}
DefaultGroupName={#AppName}
DisableProgramGroupPage=yes
OutputDir=output
OutputBaseFilename=BulkWPSender-Setup
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
; A network connection is required during install to fetch Node/PHP/deps.

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Additional icons:"

[Files]
; App source (node_modules is intentionally excluded — installed by setup.ps1)
Source: "..\engine\*";  DestDir: "{app}\engine";  Excludes: "node_modules\*"; Flags: recursesubdirs createallsubdirs
Source: "..\public\*";  DestDir: "{app}\public";  Flags: recursesubdirs createallsubdirs
Source: "..\setup.ps1";          DestDir: "{app}"
Source: "..\tray.ps1";           DestDir: "{app}"
Source: "..\BulkWPSender.vbs";   DestDir: "{app}"
Source: "..\start-servers.bat";  DestDir: "{app}"
Source: "..\stop-servers.bat";   DestDir: "{app}"
Source: "..\Troubleshoot.bat";   DestDir: "{app}"
Source: "..\sample-contacts.csv";DestDir: "{app}"
Source: "..\README.md";          DestDir: "{app}"; Flags: isreadme

[Icons]
; Launch via PowerShell directly (no .vbs — many antivirus tools block .vbs launchers).
Name: "{group}\BulkWPSender";           Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\tray.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\runtime\node\node.exe"
Name: "{group}\Troubleshoot BulkWPSender"; Filename: "{app}\Troubleshoot.bat"; WorkingDir: "{app}"
Name: "{group}\Stop BulkWPSender";      Filename: "{app}\stop-servers.bat"; WorkingDir: "{app}"
Name: "{group}\Uninstall BulkWPSender"; Filename: "{uninstallexe}"
Name: "{autodesktop}\BulkWPSender";     Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\tray.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\runtime\node\node.exe"; Tasks: desktopicon

[Run]
; Download Node.js + PHP + dependencies. Shown (not hidden) so the user sees progress.
Filename: "powershell.exe"; \
  Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\setup.ps1"" -NoShortcut"; \
  WorkingDir: "{app}"; \
  StatusMsg: "Downloading Node.js, PHP and dependencies (a few minutes)..."; \
  Flags: waituntilterminated
; Optional launch at the end.
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\tray.ps1"""; \
  WorkingDir: "{app}"; Description: "Launch BulkWPSender now"; Flags: postinstall nowait skipifsilent

[UninstallDelete]
Type: filesandordirs; Name: "{app}\runtime"
Type: filesandordirs; Name: "{app}\data"
Type: filesandordirs; Name: "{app}\engine\node_modules"
