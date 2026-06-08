# BulkWPSender — review & roadmap

A frank assessment of where the tool stands: what's solid, what was just fixed,
and what's still missing across architecture, security, bugs, UX and settings.

## ✅ Fixed in this pass
- **Engine bound to loopback only** (`127.0.0.1`) — was listening on all
  interfaces, exposing the API (send messages, read contacts) to the LAN.
- **CORS restricted to localhost origins** + **Host-header check** — blocks other
  websites / DNS-rebinding from scripting the API in the background.
- **Delay range clamped** — `min_delay > max_delay` no longer breaks the random
  wait (could previously fire with little/no gap).

## 🔒 Security — still worth knowing
- **No login on the panel.** Anyone with access to the machine can use it. Fine
  for a single-user local tool; add a password if it's ever shared.
- **Secrets stored in plaintext** in `data/app.db` (OpenAI key, Cloud API token).
  The DB never leaves the machine and tokens aren't returned by the API, but the
  file is readable by anything running as that user. Consider OS-level disk
  encryption; don't sync `data/` to cloud storage.
- **Spreadsheet parsing** uses SheetJS on user files — only ever open files you
  trust. (Local tool, low risk.)
- **Unsigned installer / executables** — Windows SmartScreen will warn. Sign with
  a code-signing certificate before distributing to others.

## 🐞 Bugs / robustness — known, not yet handled
- **Duplicate contacts** on re-import (no de-dup by phone). Add a UNIQUE index or
  upsert if needed.
- **No send retry** — a transient network blip marks a message `failed`
  permanently. A "retry failed" button would help.
- **Double-launch** starts a second engine that fails on the busy port; harmless
  but logs an error. The tray app guards this within one session, not across.
- **`sentSinceBreak` resets on restart** — batch-pause counter isn't persisted.
- **Excel numeric phones** can lose leading zeros / `+`. Always include the
  country code; storing phones as text in the sheet is safest.

## 🏗️ Architecture / features — biggest gaps
1. **No contact lists / segmentation.** Every campaign targets *all* contacts.
   Real tools need tags/lists and per-campaign audience selection. **Highest-value
   next feature.**
2. **No incoming-message / STOP handling.** For opt-out compliance, listen for
   replies and auto-flag "STOP" senders as unsubscribed (and skip them).
3. **No scheduling.** Can't queue a campaign to begin at a future time.
4. **No results export.** Add CSV/Excel export of the send log per campaign.
5. **No global defaults / per-account daily cap.** The 50/day cap is hard-coded;
   surface it (and other defaults) on the Settings page.
6. **Single worker per account, in-process.** Fine for this scale; if it ever
   grows, move the queue to a proper job runner.

## 🧭 User journey — observations
- Onboarding flow (Dashboard → Accounts → Contacts → Campaign) is clear, with
  good empty-states. ✅
- **Missing: duration estimate.** With a 50/day cap, a 1,000-contact campaign
  takes 20 days — the UI should say so before you start.
- **Missing: confirm step** before a big send (count + first preview + "are these
  opt-in?").
- Live progress + per-message log are good. A top-level "sent today across all
  numbers" with the cap would help pace usage.

## ⚙️ Settings — current vs. desirable
- **Have:** OpenAI key/model (global); per-campaign delays, daily limit, batch
  size/pause, active hours, humanization toggles, Cloud API template.
- **Desirable:** editable global defaults for new campaigns; per-account daily
  cap & display name; opt-out keyword list; panel password; backup/restore of
  `data/`.

## Suggested order of work
1. Contact lists / audience selection per campaign.
2. STOP/opt-out auto-handler (compliance).
3. Results export (CSV/Excel).
4. Campaign scheduling + duration estimate + pre-send confirm.
5. Settings: global defaults, per-account cap, optional panel password.
