# 📤 BulkWPSender

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Build Windows Installer](https://github.com/atul7275/whatsapp-automater/actions/workflows/build-installer.yml/badge.svg)](https://github.com/atul7275/whatsapp-automater/actions/workflows/build-installer.yml)

A **local** WhatsApp bulk sender for Windows with a PHP control panel and a
Node.js engine. Connect **multiple WhatsApp numbers** — humanized automation
(scan a QR, no Business API needed) and/or the **official Business Cloud API** —
each running its **own separate campaign queue**.

> ⚖️ **Please read [DISCLAIMER.md](DISCLAIMER.md) before using.** This tool
> automates WhatsApp against its Terms of Service; use it only for opted-in
> audiences and at your own risk.

> ⚠️ **Read this.** Automated bulk messaging breaks WhatsApp's Terms of Service
> and **can get a number banned** — *even when it behaves like a human*.
> Humanization lowers the *behavioral* risk, but the **50/day cap per number,
> opt-in-only audiences, and slow warm-up** are what actually keep numbers
> alive. For real volume, use the Business API. You are responsible for use.

---

## Features

**Accounts**
- Connect **many numbers at once** — personal & business, mixed
- Two channels per account: **humanized automation** (QR login) or **Business Cloud API** (official)
- Each account has its **own independent campaign flow & queue**

**Human-like sending (automation)**
- ⌨️ **Typing simulation** + online presence + "seen" before each message
- 🎲 **Natural non-uniform timing** with random "distracted" pauses
- 🕘 **Active-hours window** (e.g. only 9am–9pm)
- ☕ **Micro-breaks** + batch rest periods
- 🛡️ **Hard 50/day cap per number**
- ✅ **Number validation** (skips numbers not on WhatsApp)
- 💾 Session persistence (scan once)

**Composer (modern)**
- 📝 **Multiple message variants**, rotated per recipient
- 🤖 **AI assist (OpenAI)** — generate natural wording variations so no two messages match
- 🔤 Personalization `{{name}}`, `{{company}}`, any column · spintax `{Hi|Hello}`
- 📎 Image / PDF / document attachments
- 👁 **Live preview** with real contact data
- 🧪 **Send a test** to your own number first

**Contacts, lists & compliance**
- CSV / Excel import with auto-detected phone & name columns
- 🗂️ **Contact lists** — import into named lists; target a list or everyone per campaign
- 🚫 **STOP / opt-out auto-handler** — incoming "STOP" replies auto-unsubscribe
  (optional auto-reply); every campaign skips opt-outs; `START` re-subscribes
- ⏰ **Scheduling** — set a future start; auto-starts when due (with duration estimate + pre-send confirm)
- 📊 **Excel export** of per-campaign results
- Live progress bars, per-message logs, pause / resume / delete

---

## Architecture

```
Browser ─▶ PHP panel (localhost:8080) ─HTTP─▶ Node engine (localhost:3000)
                                               ├─ Account manager (N numbers)
                                               │   ├─ automation → whatsapp-web.js
                                               │   └─ cloud_api  → Meta Graph API
                                               ├─ Humanized sender loop (per account)
                                               └─ OpenAI assist (optional)
                                          shared SQLite at data/app.db
```

---

## Install on Windows (recommended)

You do **not** need to pre-install Node or PHP — the setup downloads private
copies automatically. See **[INSTALL.md](INSTALL.md)** for full details.

- **Portable (no build):** copy the folder to the PC (somewhere writable, not
  `Program Files`) and double-click **`install.bat`**. It downloads Node.js +
  PHP + dependencies, sets up the database on first run, adds a Desktop
  shortcut, and opens **http://localhost:8080**.
- **Build a real `.exe`:** install [Inno Setup 6](https://jrsoftware.org/isdl.php),
  then run **`installer\build-installer.bat`** to produce
  `installer\output\BulkWPSender-Setup.exe`.
- **Build the `.exe` in the cloud (no Windows PC needed):** push this repo to
  GitHub and run the **Build Windows Installer** action (Actions tab → Run
  workflow). Download the `BulkWPSender-Setup.exe` artifact. Tagging a release
  (`v1.0.0`) attaches it to the Release automatically.

Once installed, BulkWPSender runs from a **system-tray icon** — right-click for
*Open / Restart / Quit (stops the servers cleanly)*.

## Run it manually (developers, any OS)

Requires Node.js 18+ and PHP 8+ on your `PATH`:
```bash
cd engine && npm install && npm start        # http://localhost:3000
php -S localhost:8080 -t public              # http://localhost:8080
```
Open **<http://localhost:8080>**. The first `npm install` downloads a headless
Chromium (used to run WhatsApp Web), so it takes a few minutes.

---

## How to use

1. **Accounts** → *Add an account*.
   - *Automation*: scan the QR with the phone (WhatsApp → *Settings → Linked
     Devices → Link a Device*). Repeat to add more numbers.
   - *Business Cloud API*: paste your **phone number ID** + **access token**
     from the Meta developer dashboard. No QR.
2. *(Optional)* **Settings** → add an **OpenAI API key** to enable AI variations.
3. **Contacts** → upload a CSV/Excel (see `sample-contacts.csv`). Include the
   **country code** in numbers, e.g. `14155550123`.
4. **Campaigns** → pick the sending account, write one or more variants (or click
   **Generate variations** for AI), set humanization + throttling, **preview**,
   **send a test**, then **Create**.
5. Open the campaign and press **▶ Start**. Watch the live log. Multiple accounts
   can run different campaigns at the same time.

### Message example
```
{Hi|Hello} {{name}}! 👋 Thanks for being a customer of {{company}}.
Our June sale is live — {reply STOP to opt out|just let us know if interested}.
```

---

## Recommended safe settings (defaults)

| Setting | Suggested |
|---|---|
| Delay between messages | **20–60 s** (random) |
| Daily limit (automation) | **≤ 50/number**, start lower on new numbers |
| Batch / pause | **15** messages, rest **15 min** |
| Active hours | your real business hours |
| Variants | 3–5 (or AI), so no two messages match |
| Audience | **opt-in only** |

No setting guarantees no ban. Slower + smaller + genuinely-wanted = safer. Use
the **Business API** when you need reliable scale.

---

## Project layout

```
bulkwpsender/
├─ start.bat / start.sh
├─ sample-contacts.csv
├─ engine/                  Node.js engine
│  ├─ index.js              account manager, humanized sender, REST API
│  ├─ db.js                 SQLite schema + settings
│  ├─ template.js           personalization + spintax
│  ├─ cloudapi.js           WhatsApp Business Cloud API
│  └─ ai.js                 OpenAI variation generator
├─ public/                  PHP control panel
│  ├─ index.php  accounts.php  contacts.php  campaigns.php  campaign.php  settings.php
│  ├─ inc.php               config + API helpers + layout
│  └─ assets/               style.css, composer.js
└─ data/                    runtime: app.db, session/, uploads/
```

Reset a number: *Log out* on the Accounts page. Wipe everything: delete `data/`.

---

## Troubleshooting

- **"Cannot reach the engine"** → the Node engine isn't running. `cd engine && npm start`, then check `http://localhost:3000/api/accounts`.
- **QR never appears / disconnects** → delete `data/session/session-acc-<id>/` and re-add the account.
- **All sends `invalid`** → numbers missing the country code. Use digits only, e.g. `14155550123`.
- **Cloud API account shows an error** → bad/expired token or wrong phone number ID; outside the 24h window you must send an approved **template** (set its name in the campaign).
- **AI button disabled** → add an OpenAI key on the **Settings** page.
- **`npm install` fails on better-sqlite3** → install Node LTS + (Windows) "Desktop development with C++" Build Tools, then retry.

---

## Roadmap

See [docs/ROADMAP.md](docs/ROADMAP.md) for the review of known gaps and the
planned features (contact lists, opt-out handling, scheduling, results export).

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## Author & support

- **Author:** Atul Kumar
- **Company:** Future Dynamics
- **Support:** [atul7275@gmail.com](mailto:atul7275@gmail.com)
- **Issues:** <https://github.com/atul7275/whatsapp-automater/issues>

## License

[MIT](LICENSE) © 2026 Atul Kumar (Future Dynamics). Not affiliated with WhatsApp
LLC or Meta Platforms, Inc.
