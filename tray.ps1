# =============================================================================
#  BulkWPSender — system tray controller (robust launcher)
#  Starts the engine + PHP panel (hidden), shows a tray icon, opens the browser,
#  and stops both servers on quit. If anything is missing it shows a clear
#  message box instead of failing silently.
# =============================================================================
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

function Show-Msg($text, $title = 'BulkWPSender') {
  [System.Windows.Forms.MessageBox]::Show($text, $title,
    [System.Windows.Forms.MessageBoxButtons]::OK,
    [System.Windows.Forms.MessageBoxIcon]::Warning) | Out-Null
}

try {
  $Root     = Split-Path -Parent $MyInvocation.MyCommand.Path
  $NodeExe  = Join-Path $Root "runtime\node\node.exe"
  $PhpExe   = Join-Path $Root "runtime\php\php.exe"
  $PhpIni   = Join-Path $Root "runtime\php\php.ini"
  $Engine   = Join-Path $Root "engine\index.js"
  $Modules  = Join-Path $Root "engine\node_modules"
  $PanelDir = Join-Path $Root "public"
  $Data     = Join-Path $Root "data"
  $Url      = "http://localhost:8080"

  New-Item -ItemType Directory -Force -Path $Data | Out-Null

  # --- preflight: make sure setup actually completed ----------------------
  $missing = @()
  if (-not (Test-Path $NodeExe)) { $missing += "Node runtime (runtime\node\node.exe)" }
  if (-not (Test-Path $PhpExe))  { $missing += "PHP runtime (runtime\php\php.exe)" }
  if (-not (Test-Path $Modules)) { $missing += "App dependencies (engine\node_modules)" }
  if ($missing.Count -gt 0) {
    Show-Msg ("Setup is incomplete - these parts are missing:`n`n - " +
      ($missing -join "`n - ") +
      "`n`nRun 'Troubleshoot.bat' in the install folder to finish setup, then try again.")
    return
  }

  $script:engineProc = $null
  $script:phpProc    = $null

  function Start-Servers {
    if (-not $script:engineProc -or $script:engineProc.HasExited) {
      $script:engineProc = Start-Process -FilePath $NodeExe -ArgumentList "`"$Engine`"" `
        -WorkingDirectory $Root -WindowStyle Hidden -PassThru
    }
    Start-Sleep -Seconds 3
    if (-not $script:phpProc -or $script:phpProc.HasExited) {
      $script:phpProc = Start-Process -FilePath $PhpExe `
        -ArgumentList "-c `"$PhpIni`" -S localhost:8080 -t `"$PanelDir`"" `
        -WorkingDirectory $Root -WindowStyle Hidden -PassThru
    }
  }
  function Stop-Servers {
    foreach ($p in @($script:phpProc, $script:engineProc)) {
      if ($p -and -not $p.HasExited) { try { $p.Kill() } catch {} }
    }
  }

  # --- tray icon FIRST, so the user always sees something -----------------
  try { $ico = [System.Drawing.Icon]::ExtractAssociatedIcon($NodeExe) }
  catch { $ico = [System.Drawing.SystemIcons]::Application }

  $tray = New-Object System.Windows.Forms.NotifyIcon
  $tray.Icon = $ico
  $tray.Text = "BulkWPSender"
  $tray.Visible = $true

  $menu = New-Object System.Windows.Forms.ContextMenuStrip
  $open = $menu.Items.Add("Open BulkWPSender");  $open.add_Click({ Start-Process $Url })
  $restart = $menu.Items.Add("Restart servers"); $restart.add_Click({ Stop-Servers; Start-Sleep -Seconds 1; Start-Servers })
  [void]$menu.Items.Add("-")
  $quit = $menu.Items.Add("Quit (stop servers)")
  $quit.add_Click({ Stop-Servers; $tray.Visible = $false; [System.Windows.Forms.Application]::Exit() })
  $tray.ContextMenuStrip = $menu
  $tray.add_DoubleClick({ Start-Process $Url })

  Start-Servers
  Start-Sleep -Seconds 2
  Start-Process $Url
  $tray.ShowBalloonTip(3000, "BulkWPSender", "Running at $Url - right-click the tray icon to stop.", "Info")

  [System.Windows.Forms.Application]::Run()
  Stop-Servers
}
catch {
  Show-Msg ("BulkWPSender could not start:`n`n" + $_.Exception.Message +
    "`n`nRun 'Troubleshoot.bat' in the install folder for details.")
}
