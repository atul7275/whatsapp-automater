// SQLite database setup. The DB file lives in ../data/app.db and is the shared
// store between the PHP control panel (which calls the API) and this engine.
const Database = require('better-sqlite3');
const path = require('path');
const fs = require('fs');

const DATA_DIR = path.join(__dirname, '..', 'data');
fs.mkdirSync(DATA_DIR, { recursive: true });

const db = new Database(path.join(DATA_DIR, 'app.db'));
db.pragma('journal_mode = WAL');
db.pragma('busy_timeout = 5000');

db.exec(`
-- A connected WhatsApp number. type = 'automation' (whatsapp-web.js) or 'cloud_api'.
CREATE TABLE IF NOT EXISTS accounts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT NOT NULL,
  type          TEXT NOT NULL DEFAULT 'automation',
  cloud_phone_id TEXT,
  cloud_token    TEXT,
  cloud_lang     TEXT DEFAULT 'en_US',
  created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS contacts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL DEFAULT '',
  phone      TEXT NOT NULL,
  fields     TEXT NOT NULL DEFAULT '{}',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS campaigns (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id   INTEGER NOT NULL,
  name         TEXT NOT NULL,
  variants     TEXT NOT NULL DEFAULT '[]',   -- JSON array of message templates (rotated)
  media_path   TEXT,
  media_name   TEXT,
  status       TEXT NOT NULL DEFAULT 'draft', -- draft|running|paused|done
  min_delay    INTEGER NOT NULL DEFAULT 20,
  max_delay    INTEGER NOT NULL DEFAULT 60,
  daily_limit  INTEGER NOT NULL DEFAULT 50,   -- capped at 50 for automation accounts
  batch_size   INTEGER NOT NULL DEFAULT 15,
  batch_pause  INTEGER NOT NULL DEFAULT 15,   -- minutes
  active_from  INTEGER NOT NULL DEFAULT 9,    -- hour 0-23, -1 = always
  active_to    INTEGER NOT NULL DEFAULT 21,   -- hour 0-23, -1 = always
  human_typing INTEGER NOT NULL DEFAULT 1,
  natural_timing INTEGER NOT NULL DEFAULT 1,
  micro_breaks INTEGER NOT NULL DEFAULT 1,
  cloud_template TEXT,                         -- for cloud_api: approved template name
  created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS messages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id INTEGER NOT NULL,
  account_id  INTEGER NOT NULL,
  contact_id  INTEGER,
  phone       TEXT NOT NULL,
  name        TEXT NOT NULL DEFAULT '',
  rendered    TEXT NOT NULL,
  status      TEXT NOT NULL DEFAULT 'pending', -- pending|sent|failed|invalid
  error       TEXT,
  sent_at     TEXT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT
);

-- Contact lists (audience segments). Many-to-many via contact_list.
CREATE TABLE IF NOT EXISTS lists (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS contact_list (
  contact_id INTEGER NOT NULL,
  list_id    INTEGER NOT NULL,
  UNIQUE(contact_id, list_id)
);

-- Opt-outs: phones that replied STOP (or were added manually). Skipped on send.
CREATE TABLE IF NOT EXISTS optouts (
  phone      TEXT PRIMARY KEY,
  keyword    TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE INDEX IF NOT EXISTS idx_messages_campaign ON messages(campaign_id, status);
CREATE INDEX IF NOT EXISTS idx_messages_acct_sent ON messages(account_id, status, sent_at);
CREATE INDEX IF NOT EXISTS idx_contact_list ON contact_list(list_id, contact_id);
`);

// --- forward-compatible column migrations (safe to run repeatedly) ----------
function ensureColumns(table, cols) {
  const have = db.prepare(`PRAGMA table_info(${table})`).all().map(c => c.name);
  for (const [name, def] of Object.entries(cols)) {
    if (!have.includes(name)) db.exec(`ALTER TABLE ${table} ADD COLUMN ${name} ${def}`);
  }
}
ensureColumns('campaigns', {
  active_from: 'INTEGER NOT NULL DEFAULT 9',
  active_to: 'INTEGER NOT NULL DEFAULT 21',
  human_typing: 'INTEGER NOT NULL DEFAULT 1',
  natural_timing: 'INTEGER NOT NULL DEFAULT 1',
  micro_breaks: 'INTEGER NOT NULL DEFAULT 1',
  cloud_template: 'TEXT',
  list_id: 'INTEGER',                              // NULL = all contacts
  scheduled_at: 'TEXT',                            // NULL = not scheduled; else 'YYYY-MM-DD HH:MM:SS' local
});
ensureColumns('contacts', {
  unsubscribed: 'INTEGER NOT NULL DEFAULT 0',
});
ensureColumns('accounts', {
  daily_cap: 'INTEGER NOT NULL DEFAULT 0',           // 0 = use campaign limit / 50 ceiling
});

// --- tiny settings helpers -------------------------------------------------
const getSetting = (k, d = null) => {
  const r = db.prepare(`SELECT value FROM settings WHERE key=?`).get(k);
  return r ? r.value : d;
};
const setSetting = (k, v) =>
  db.prepare(`INSERT INTO settings (key, value) VALUES (?, ?)
              ON CONFLICT(key) DO UPDATE SET value=excluded.value`).run(k, v);

// seed defaults the first time only (don't clobber user edits)
if (getSetting('optout_keywords') === null)
  setSetting('optout_keywords', 'stop, unsubscribe, cancel, remove me, opt out');
if (getSetting('optout_reply') === null)
  setSetting('optout_reply', "You've been unsubscribed and won't receive further messages. Reply START to opt back in.");

// default campaign settings (pre-fill the composer); seeded once
const DEFAULTS = {
  def_min_delay: '20', def_max_delay: '60', def_daily_limit: '50',
  def_batch_size: '15', def_batch_pause: '15', def_active_from: '9', def_active_to: '21',
  def_human_typing: '1', def_natural_timing: '1', def_micro_breaks: '1',
};
for (const [k, v] of Object.entries(DEFAULTS)) if (getSetting(k) === null) setSetting(k, v);

module.exports = { db, getSetting, setSetting };
