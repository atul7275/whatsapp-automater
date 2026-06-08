# Installing BulkWPSender on Windows

There are **two ways** to install. Both end the same way: a self-contained app
that auto-downloads everything it needs and opens at **http://localhost:8080**.

You do **not** need to pre-install Node.js or PHP — the setup downloads its own
private copies into the app folder. You only need an internet connection.

---

## Option A — Portable (no build, easiest)

Use this to run it immediately, or to test before building the `.exe`.

1. Copy the whole `bulkwpsender` folder onto the Windows PC (e.g. `C:\BulkWPSender`).
   Put it somewhere writable — **not** inside `Program Files`.
2. Double-click **`install.bat`**.
3. Wait. It downloads Node.js, PHP, and the app dependencies (including the
   Chromium that runs WhatsApp Web). First run takes a few minutes.
4. When it finishes, the app opens at **http://localhost:8080** and a
   **BulkWPSender** shortcut is added to your Desktop.

Next time: just use the **Desktop shortcut**. To stop the servers, close the two
minimized console windows (or run `stop-servers.bat`).

---

## Option B — Build a real installer (`BulkWPSender-Setup.exe`)

This produces a single distributable `.exe` that installs the app, downloads
dependencies, creates Start-Menu/Desktop shortcuts, and registers an uninstaller.

**One-time tooling:** install **Inno Setup 6** — <https://jrsoftware.org/isdl.php>

**Build:**
1. Double-click **`installer\build-installer.bat`**
   *(or open `installer\bulkwpsender.iss` in the Inno Setup Compiler and press F9).*
2. The installer is written to **`installer\output\BulkWPSender-Setup.exe`**.

**What the `.exe` does when a user runs it:**
1. Installs the app to `%LOCALAPPDATA%\BulkWPSender` (per-user, writable, no admin).
2. Runs `setup.ps1`, which **downloads Node.js + PHP + dependencies**.
3. Creates Desktop + Start-Menu shortcuts.
4. Offers to launch — opening **http://localhost:8080**.

The database (SQLite at `data\app.db`) is created automatically the first time
the engine starts; there is no separate database step.

---

## What gets installed where

```
<install folder>\
├─ engine\           Node service (+ node_modules after setup)
├─ public\           PHP control panel
├─ runtime\
│  ├─ node\          private Node.js  (downloaded)
│  └─ php\           private PHP       (downloaded, with php.ini)
├─ data\             app.db, session\, uploads\  (created at runtime)
├─ BulkWPSender.vbs  launcher (starts servers + opens browser)
├─ start-servers.bat / stop-servers.bat
└─ setup.ps1
```

Ports used: **8080** (panel) and **3000** (engine). If something else uses them,
edit `start-servers.bat` (panel port) and set `PORT` for the engine.

---

## Troubleshooting

- **"Running scripts is disabled"** → `install.bat` already bypasses this; if you
  run `setup.ps1` by hand use:
  `powershell -ExecutionPolicy Bypass -File setup.ps1`
- **SmartScreen warns about the .exe** → it's unsigned. Click *More info → Run
  anyway*, or sign it with a code-signing certificate for distribution.
- **Download fails** → check internet/proxy/firewall, then re-run `install.bat`
  (it resumes — already-downloaded parts are skipped).
- **Antivirus flags the bundled Chromium/Node** → whitelist the install folder.
- **Port already in use** → close the old server windows or change the ports.
