# =============================================================================
#  BulkWPSender — system tray controller
#  Starts the engine + PHP panel (hidden), shows a tray icon with Open / Restart
#  / Quit, opens the browser, and stops both servers cleanly when you quit.
#  Launched hidden by BulkWPSender.vbs.
# =============================================================================
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$Root     = Split-Path -Parent $MyInvocation.MyCommand.Path
$NodeExe  = Join-Path $Root "runtime\node\node.exe"
$PhpExe   = Join-Path $Root "runtime\php\php.exe"
$PhpIni   = Join-Path $Root "runtime\php\php.ini"
$Engine   = Join-Path $Root "engine\index.js"
$PanelDir = Join-Path $Root "public"
$Url      = "http://localhost:8080"

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

Start-Servers

# tray icon (use the Node icon if we can, else a default)
try { $ico = [System.Drawing.Icon]::ExtractAssociatedIcon($NodeExe) }
catch { $ico = [System.Drawing.SystemIcons]::Application }

$tray = New-Object System.Windows.Forms.NotifyIcon
$tray.Icon = $ico
$tray.Text = "BulkWPSender"
$tray.Visible = $true

$menu = New-Object System.Windows.Forms.ContextMenuStrip
$open = $menu.Items.Add("Open BulkWPSender")
$open.add_Click({ Start-Process $Url })
$restart = $menu.Items.Add("Restart servers")
$restart.add_Click({ Stop-Servers; Start-Sleep -Seconds 1; Start-Servers
  $tray.ShowBalloonTip(2000, "BulkWPSender", "Servers restarted.", "Info") })
[void]$menu.Items.Add("-")
$quit = $menu.Items.Add("Quit (stop servers)")
$quit.add_Click({ Stop-Servers; $tray.Visible = $false; [System.Windows.Forms.Application]::Exit() })
$tray.ContextMenuStrip = $menu
$tray.add_DoubleClick({ Start-Process $Url })

# open the panel once the engine has had a moment to boot
Start-Sleep -Seconds 2
Start-Process $Url
$tray.ShowBalloonTip(3000, "BulkWPSender", "Running at $Url — right-click the tray icon to stop.", "Info")

# keep the tray alive until Quit
[System.Windows.Forms.Application]::Run()
Stop-Servers
