# =============================================================================
#  BulkWPSender — setup / bootstrap
#  Downloads a portable Node.js + PHP, installs app dependencies (incl. the
#  Chromium used by WhatsApp Web), writes a php.ini, and creates shortcuts.
#  Safe to re-run: anything already present is skipped.
# =============================================================================
param([switch]$NoShortcut)

$ErrorActionPreference = "Stop"
$Root    = Split-Path -Parent $MyInvocation.MyCommand.Path
$Runtime = Join-Path $Root "runtime"
$NodeDir = Join-Path $Runtime "node"
$PhpDir  = Join-Path $Runtime "php"
$NodeVer = "v20.18.1"          # pinned LTS (kept available on nodejs.org)

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
New-Item -ItemType Directory -Force -Path $Runtime | Out-Null

function Get-File($url, $out) {
  Write-Host "  -> $url"
  Invoke-WebRequest -Uri $url -OutFile $out -UseBasicParsing
}

# --- Node.js ----------------------------------------------------------------
if (-not (Test-Path (Join-Path $NodeDir "node.exe"))) {
  Write-Host "[1/4] Downloading Node.js $NodeVer ..."
  $zip = Join-Path $env:TEMP "bwps-node.zip"
  Get-File "https://nodejs.org/dist/$NodeVer/node-$NodeVer-win-x64.zip" $zip
  Write-Host "      Extracting ..."
  Expand-Archive -Path $zip -DestinationPath $Runtime -Force
  if (Test-Path $NodeDir) { Remove-Item $NodeDir -Recurse -Force }
  Rename-Item (Join-Path $Runtime "node-$NodeVer-win-x64") $NodeDir
  Remove-Item $zip -Force
} else { Write-Host "[1/4] Node.js already present - skipping." }

# --- PHP (auto-discover latest 8.3 NTS x64) ---------------------------------
if (-not (Test-Path (Join-Path $PhpDir "php.exe"))) {
  Write-Host "[2/4] Locating latest PHP 8.3 ..."
  $file = $null; $base = ""
  try {
    $page = (Invoke-WebRequest "https://windows.php.net/downloads/releases/" -UseBasicParsing).Content
    $hit  = [regex]::Matches($page, 'php-8\.3\.\d+-nts-Win32-vs16-x64\.zip')
    if ($hit.Count -gt 0) { $file = $hit[0].Value }
  } catch { }
  if (-not $file) { $file = "php-8.3.14-nts-Win32-vs16-x64.zip"; $base = "archives/" }  # fallback
  Write-Host "[2/4] Downloading PHP ($file) ..."
  $zip = Join-Path $env:TEMP "bwps-php.zip"
  Get-File "https://windows.php.net/downloads/releases/$base$file" $zip
  Write-Host "      Extracting ..."
  New-Item -ItemType Directory -Force -Path $PhpDir | Out-Null
  Expand-Archive -Path $zip -DestinationPath $PhpDir -Force
  Remove-Item $zip -Force
} else { Write-Host "[2/4] PHP already present - skipping download." }

# make sure the data dir exists (PHP error_log + engine DB live here)
New-Item -ItemType Directory -Force -Path (Join-Path $Root "data") | Out-Null

# ALWAYS (re)write php.ini, even when PHP was already present — an old install may
# have a broken php.ini (relative extension_dir) that stops curl from loading,
# which breaks every panel page. extension_dir MUST be an absolute path.
if (Test-Path (Join-Path $PhpDir "php.exe")) {
  $extDir = (Join-Path $PhpDir "ext") -replace '\\', '/'
  $errLog = (Join-Path $Root "data\php-error.log") -replace '\\', '/'
  @"
extension_dir = "$extDir"
extension=curl
extension=openssl
extension=mbstring
extension=fileinfo
log_errors = On
error_log = "$errLog"
"@ | Set-Content -Encoding ASCII -Path (Join-Path $PhpDir "php.ini")
  Write-Host "      php.ini written (curl enabled, absolute extension_dir)."
}

# --- App dependencies (Node modules + Chromium) -----------------------------
# Only (re)install when missing, broken, or the app version changed — so an
# update with unchanged dependencies does NOT re-download anything.
$npm     = Join-Path $NodeDir "npm.cmd"
$nodeExe = Join-Path $NodeDir "node.exe"
$engineDir = Join-Path $Root "engine"
$modules = Join-Path $engineDir "node_modules"
$marker  = Join-Path $Runtime ".deps-version"
$pkgVer  = "0"
try { $pkgVer = (Get-Content (Join-Path $engineDir "package.json") -Raw | ConvertFrom-Json).version } catch {}

$needInstall = $true
if ((Test-Path $modules) -and (Test-Path $marker)) {
  $have = (Get-Content $marker -ErrorAction SilentlyContinue | Select-Object -First 1)
  if ("$have".Trim() -eq "$pkgVer") {
    & $nodeExe -e "require('better-sqlite3');require('whatsapp-web.js');require('express')" 2>$null
    if ($LASTEXITCODE -eq 0) { $needInstall = $false }
  }
}

if ($needInstall) {
  Write-Host "[3/4] Installing app dependencies (first time downloads Chromium - a few minutes) ..."
  Push-Location $engineDir
  & $npm install --no-audit --no-fund
  $rc = $LASTEXITCODE
  Pop-Location
  if ($rc -ne 0) { throw "npm install failed (exit $rc)." }
  Set-Content -Path $marker -Value $pkgVer -Encoding ASCII
} else {
  Write-Host "[3/4] Dependencies already installed and matching v$pkgVer - skipping download."
}

# --- Shortcuts --------------------------------------------------------------
if (-not $NoShortcut) {
  Write-Host "[4/4] Creating shortcuts ..."
  $vbs = Join-Path $Root "BulkWPSender.vbs"
  $ws  = New-Object -ComObject WScript.Shell
  $targets = @(
    [Environment]::GetFolderPath("Desktop"),
    (Join-Path ([Environment]::GetFolderPath("StartMenu")) "Programs")
  )
  foreach ($d in $targets) {
    $lnk = $ws.CreateShortcut((Join-Path $d "BulkWPSender.lnk"))
    $lnk.TargetPath       = "wscript.exe"
    $lnk.Arguments        = '"' + $vbs + '"'
    $lnk.WorkingDirectory = $Root
    $lnk.IconLocation     = (Join-Path $NodeDir "node.exe")
    $lnk.Save()
  }
} else { Write-Host "[4/4] Skipping shortcuts (installer handles them)." }

Write-Host ""
Write-Host "============================================================"
Write-Host "  Setup complete!"
Write-Host "  Start BulkWPSender from the Desktop shortcut, then open:"
Write-Host "      http://localhost:8080"
Write-Host "============================================================"
