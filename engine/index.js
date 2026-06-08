// ===========================================================================
//  BulkWPSender — Node engine (multi-account, humanized)
//
//  - Manages MANY WhatsApp numbers at once (automation + Business Cloud API),
//    each with its own independent campaign queue and sender flow.
//  - Humanized automation: typing simulation, presence/seen, natural non-uniform
//    timing, active-hours window, micro-breaks, and a hard 50/day cap per number.
//  - Optional OpenAI assist to generate natural message variations.
//  - REST API consumed by the PHP control panel.
//
//  LOCAL USE ONLY. Even humanized, automated bulk messaging breaks WhatsApp's
//  ToS and can get a number banned. Only message people who opted in.
// ===========================================================================
// --- bootstrap: set up logging + crash handlers BEFORE the heavy requires, so a
//     failed dependency load (e.g. the native better-sqlite3 module) is captured
//     to data/engine.log instead of vanishing silently. --------------------------
const path = require('path');
const fs = require('fs');

const APP_VERSION = '1.7.0';
const REPO = 'atul7275/whatsapp-automater';
const PORT = process.env.PORT || 3000;
const DATA = path.join(__dirname, '..', 'data');
const SESSION_DIR = path.join(DATA, 'session');
const UPLOAD_DIR = path.join(DATA, 'uploads');
try { fs.mkdirSync(UPLOAD_DIR, { recursive: true }); fs.mkdirSync(SESSION_DIR, { recursive: true }); } catch (_) {}

try {
  const logStream = fs.createWriteStream(path.join(DATA, 'engine.log'), { flags: 'a' });
  const tee = (orig) => (...a) => { try { logStream.write(a.map(String).join(' ') + '\n'); } catch (_) {} orig(...a); };
  console.log = tee(console.log.bind(console));
  console.error = tee(console.error.bind(console));
} catch (_) {}

let booted = false;
process.on('uncaughtException', (e) => {
  console.error('[FATAL] uncaught:', e && e.stack ? e.stack : e);
  if (!booted) {
    console.error('[FATAL] The engine failed to start — usually the install did not finish.');
    console.error('[FATAL] Run Troubleshoot.bat in the install folder to repair dependencies.');
    process.exit(1);
  }
});
process.on('unhandledRejection', (e) => console.error('[unhandledRejection]', e && e.stack ? e.stack : e));
console.log(`[boot] BulkWPSender engine v${APP_VERSION} starting (node ${process.version}, ${process.platform}) ${new Date().toISOString()}`);

const express = require('express');
const cors = require('cors');
const multer = require('multer');
const qrcode = require('qrcode');
const os = require('os');
const crypto = require('crypto');
const { spawn } = require('child_process');
const XLSX = require('xlsx');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

const { db, getSetting, setSetting } = require('./db');
const { render } = require('./template');
const cloudapi = require('./cloudapi');
const ai = require('./ai');

const AUTOMATION_DAILY_CAP = 50; // hard safety ceiling per number

const rand = (min, max) => Math.floor(Math.random() * (max - min + 1)) + min;
const sleep = (ms) => new Promise(r => setTimeout(r, ms));
// active-hours field: '' / missing / invalid -> -1 (always); else 0..23
const hourOrAlways = (v) => {
  if (v === '' || v == null) return -1;
  const n = parseInt(v, 10);
  return Number.isNaN(n) ? -1 : n;
};

// ===========================================================================
//  Account manager — one runtime record per connected number
// ===========================================================================
// rec = { id, type, client?, state, qr, info, busy, sentSinceBreak }
const accounts = new Map();

function recPublic(rec) {
  return { id: rec.id, type: rec.type, state: rec.state, qr: rec.qr, info: rec.info };
}

function bootAutomation(acc) {
  const client = new Client({
    authStrategy: new LocalAuth({ clientId: 'acc-' + acc.id, dataPath: SESSION_DIR }),
    puppeteer: { headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] },
  });
  const rec = { id: acc.id, type: 'automation', client, state: 'starting', qr: null, info: null, busy: false, sentSinceBreak: 0 };
  accounts.set(acc.id, rec);

  client.on('qr', async (qr) => { rec.state = 'qr'; rec.qr = await qrcode.toDataURL(qr); });
  client.on('authenticated', () => { rec.state = 'authenticated'; rec.qr = null; });
  client.on('ready', () => {
    rec.state = 'ready'; rec.qr = null;
    rec.info = client.info ? { pushname: client.info.pushname, number: client.info.wid?.user } : null;
    console.log(`[acc ${acc.id}] ready as ${rec.info?.number}`);
  });
  client.on('disconnected', (r) => { rec.state = 'disconnected'; rec.info = null; console.log(`[acc ${acc.id}] disconnected: ${r}`); });
  client.on('auth_failure', () => { rec.state = 'disconnected'; });
  client.on('message', (m) => handleIncoming(rec, m)); // STOP/opt-out auto-handling
  client.initialize();
}

function bootCloud(acc) {
  const rec = { id: acc.id, type: 'cloud_api', state: 'ready', qr: null, busy: false, sentSinceBreak: 0,
    info: { number: acc.cloud_phone_id } };
  accounts.set(acc.id, rec);
  // best-effort verify to surface bad creds
  cloudapi.verify(acc)
    .then(d => { rec.info = { pushname: d.verified_name, number: d.display_phone_number }; })
    .catch(e => { rec.state = 'error'; rec.info = { error: String(e.message || e) }; });
}

function bootAccount(acc) {
  if (acc.type === 'cloud_api') bootCloud(acc); else bootAutomation(acc);
}

async function destroyAccount(id) {
  const rec = accounts.get(id);
  if (rec?.client) { try { await rec.client.destroy(); } catch (_) {} }
  accounts.delete(id);
  // remove its session folder
  const dir = path.join(SESSION_DIR, 'session-acc-' + id);
  fs.rm(dir, { recursive: true, force: true }, () => {});
}

// boot everything already in the DB
for (const acc of db.prepare(`SELECT * FROM accounts`).all()) bootAccount(acc);

// ===========================================================================
//  Humanized sender
// ===========================================================================
function sentTodayForAccount(accountId) {
  return db.prepare(
    `SELECT COUNT(*) c FROM messages WHERE account_id=? AND status='sent' AND date(sent_at)=date('now','localtime')`
  ).get(accountId).c;
}

function effectiveCap(campaign, rec) {
  const acc = db.prepare(`SELECT daily_cap FROM accounts WHERE id=?`).get(rec.id) || {};
  const accCap = acc.daily_cap > 0 ? acc.daily_cap : 0;
  if (rec.type === 'automation') {
    let cap = AUTOMATION_DAILY_CAP;                              // hard 50 ceiling
    if (campaign.daily_limit > 0) cap = Math.min(cap, campaign.daily_limit);
    if (accCap > 0) cap = Math.min(cap, accCap);
    return cap;
  }
  // cloud api: 0 = unlimited unless an account cap is set
  let cap = campaign.daily_limit > 0 ? campaign.daily_limit : 0;
  if (accCap > 0) cap = cap > 0 ? Math.min(cap, accCap) : accCap;
  return cap;
}

function withinActiveHours(campaign) {
  if (campaign.active_from < 0 || campaign.active_to < 0) return true;
  const h = new Date().getHours();
  const { active_from: a, active_to: b } = campaign;
  return a <= b ? (h >= a && h < b) : (h >= a || h < b); // supports overnight wrap
}

// Time spent "typing", proportional to length, like a real person (~5 cps).
function typingTime(text) {
  const base = (text.length / rand(4, 6)) * 1000;
  return Math.min(11000, Math.max(1500, base)) + rand(0, 800);
}

// Delay before the NEXT message — non-uniform with occasional human pauses.
function humanDelay(campaign) {
  const lo = Math.max(3, Math.min(campaign.min_delay, campaign.max_delay));
  const hi = Math.max(lo, campaign.max_delay, campaign.min_delay);
  let d = rand(lo, hi);
  if (campaign.natural_timing) {
    d += rand(-2, 4);                                   // small jitter
    if (Math.random() < 0.15) d += rand(20, 90);        // "distracted" pause
  }
  if (campaign.micro_breaks && Math.random() < 0.06) d += rand(60, 180); // micro-break
  return Math.max(3, d) * 1000;
}

// --- opt-out / STOP handling (automation accounts) -------------------------
const RESUB_WORDS = ['start', 'unstop', 'subscribe', 'resume', 'opt in'];
const normPhone = (s) => String(s || '').replace(/\D/g, '');
// whole-word / whole-phrase match, so "stop" doesn't fire on "stopping"
function wordHit(body, kw) {
  if (!kw) return false;
  const esc = kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`(^|\\W)${esc}(\\W|$)`, 'i').test(body);
}
async function handleIncoming(rec, msg) {
  try {
    if (!msg || msg.fromMe) return;
    const from = msg.from || '';
    if (!from.endsWith('@c.us')) return;            // ignore groups/status/broadcast
    const phone = normPhone(from.split('@')[0]);
    const body = String(msg.body || '').trim().toLowerCase();
    if (!phone || !body) return;

    if (RESUB_WORDS.some(w => wordHit(body, w))) {
      db.prepare(`DELETE FROM optouts WHERE phone=?`).run(phone);
      db.prepare(`UPDATE contacts SET unsubscribed=0 WHERE phone=?`).run(phone);
      console.log(`[optout] ${phone} re-subscribed`);
      return;
    }

    const keywords = (getSetting('optout_keywords', '') || '')
      .split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
    const hit = keywords.find(k => wordHit(body, k));
    if (!hit) return;

    const already = db.prepare(`SELECT 1 FROM optouts WHERE phone=?`).get(phone);
    db.prepare(`INSERT OR IGNORE INTO optouts (phone, keyword) VALUES (?, ?)`).run(phone, hit);
    db.prepare(`UPDATE contacts SET unsubscribed=1 WHERE phone=?`).run(phone);
    console.log(`[optout] ${phone} opted out via "${hit}"`);

    if (!already) {
      const reply = (getSetting('optout_reply', '') || '').trim();
      if (reply) { try { await rec.client.sendMessage(from, reply); } catch (_) {} }
    }
  } catch (e) { console.error('[handleIncoming]', e); }
}

function markSent(id)   { db.prepare(`UPDATE messages SET status='sent', error=NULL, sent_at=datetime('now','localtime') WHERE id=?`).run(id); }
function markInvalid(id){ db.prepare(`UPDATE messages SET status='invalid', error='Not on WhatsApp', sent_at=datetime('now','localtime') WHERE id=?`).run(id); }
function markFailed(id, e){ db.prepare(`UPDATE messages SET status='failed', error=?, sent_at=datetime('now','localtime') WHERE id=?`).run(String(e && e.message ? e.message : e).slice(0,500), id); }

async function sendViaAutomation(rec, campaign, msg) {
  const client = rec.client;
  let numberId;
  try { numberId = await client.getNumberId(msg.phone); }
  catch (e) { markFailed(msg.id, e); return 'failed'; }
  if (!numberId) { markInvalid(msg.id); return 'invalid'; }

  const chatId = numberId._serialized;
  try {
    await client.sendPresenceAvailable().catch(() => {});
    const chat = await client.getChatById(chatId);
    await chat.sendSeen().catch(() => {});
    if (campaign.human_typing) {
      await chat.sendStateTyping().catch(() => {});
      await sleep(typingTime(msg.rendered));
      await chat.clearState().catch(() => {});
    }
    if (campaign.media_path && fs.existsSync(campaign.media_path)) {
      const media = MessageMedia.fromFilePath(campaign.media_path);
      await client.sendMessage(chatId, media, { caption: msg.rendered || undefined });
    } else {
      await client.sendMessage(chatId, msg.rendered);
    }
    markSent(msg.id);
    return 'sent';
  } catch (e) { markFailed(msg.id, e); return 'failed'; }
}

async function sendViaCloud(rec, campaign, msg) {
  const acc = db.prepare(`SELECT * FROM accounts WHERE id=?`).get(rec.id);
  try {
    await cloudapi.send(acc, campaign, msg.phone, msg.rendered);
    markSent(msg.id);
    return 'sent';
  } catch (e) { markFailed(msg.id, e); return 'failed'; }
}

async function processAccount(rec) {
  rec.busy = true;
  try {
    const campaign = db.prepare(`SELECT * FROM campaigns WHERE account_id=? AND status='running' ORDER BY id LIMIT 1`).get(rec.id);
    if (!campaign) { rec.busy = false; return; }
    if (!withinActiveHours(campaign)) { rec.busy = false; return; }

    const cap = effectiveCap(campaign, rec);
    if (cap > 0 && sentTodayForAccount(rec.id) >= cap) { rec.busy = false; return; }

    const msg = db.prepare(`SELECT * FROM messages WHERE campaign_id=? AND status='pending' ORDER BY id LIMIT 1`).get(campaign.id);
    if (!msg) { db.prepare(`UPDATE campaigns SET status='done' WHERE id=?`).run(campaign.id); rec.busy = false; return; }

    const result = rec.type === 'cloud_api'
      ? await sendViaCloud(rec, campaign, msg)
      : await sendViaAutomation(rec, campaign, msg);
    console.log(`[send] acc ${rec.id} campaign ${campaign.id} -> ${msg.phone}: ${result}`);

    let delayMs = humanDelay(campaign);
    if (result === 'sent') {
      rec.sentSinceBreak++;
      if (campaign.batch_size > 0 && rec.sentSinceBreak >= campaign.batch_size) {
        delayMs = campaign.batch_pause * 60 * 1000;
        rec.sentSinceBreak = 0;
        console.log(`[send] acc ${rec.id} batch done — resting ${campaign.batch_pause} min`);
      }
    }
    setTimeout(() => { rec.busy = false; }, delayMs);
  } catch (e) { console.error('[processAccount]', e); rec.busy = false; }
}

// promote scheduled campaigns whose start time has arrived
function promoteScheduled() {
  const due = db.prepare(
    `SELECT id, account_id FROM campaigns WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= datetime('now','localtime')`
  ).all();
  for (const c of due) {
    db.prepare(`UPDATE campaigns SET status='paused' WHERE status='running' AND account_id=? AND id<>?`).run(c.account_id, c.id);
    db.prepare(`UPDATE campaigns SET status='running' WHERE id=?`).run(c.id);
    const rec = accounts.get(c.account_id); if (rec) rec.sentSinceBreak = 0;
    console.log(`[schedule] campaign ${c.id} started (scheduled time reached)`);
  }
}

// master tick: drive every ready, idle account independently (parallel flows)
setInterval(() => {
  promoteScheduled();
  for (const rec of accounts.values()) {
    if (rec.state === 'ready' && !rec.busy) processAccount(rec);
  }
}, 2000);

// ===========================================================================
//  REST API
// ===========================================================================
const app = express();
// Only allow the local control panel as an origin (blocks other sites that the
// user might visit from scripting this API in the background).
app.use(cors({ origin: [/^http:\/\/localhost(:\d+)?$/, /^http:\/\/127\.0\.0\.1(:\d+)?$/] }));
// Defend against DNS-rebinding: reject requests whose Host isn't localhost.
app.use((req, res, next) => {
  const host = (req.headers.host || '').split(':')[0];
  if (host !== 'localhost' && host !== '127.0.0.1') return res.status(403).end();
  next();
});
app.use(express.json());
const upload = multer({ dest: UPLOAD_DIR });

// --- accounts -----------------------------------------------------------
app.get('/api/accounts', (req, res) => {
  const rows = db.prepare(`SELECT id, name, type, cloud_phone_id, cloud_lang, daily_cap, created_at FROM accounts ORDER BY id`).all();
  for (const r of rows) {
    const rec = accounts.get(r.id);
    r.state = rec ? rec.state : 'offline';
    r.qr = rec ? rec.qr : null;
    r.info = rec ? rec.info : null;
    r.sent_today = sentTodayForAccount(r.id);
  }
  res.json({ rows });
});

app.post('/api/accounts', (req, res) => {
  const b = req.body || {};
  if (!b.name) return res.status(400).json({ error: 'Name required' });
  const type = b.type === 'cloud_api' ? 'cloud_api' : 'automation';
  if (type === 'cloud_api' && (!b.cloud_phone_id || !b.cloud_token))
    return res.status(400).json({ error: 'Cloud API needs phone number ID and token' });

  const cap = Math.max(0, parseInt(b.daily_cap) || 0);
  const info = db.prepare(`INSERT INTO accounts (name, type, cloud_phone_id, cloud_token, cloud_lang, daily_cap) VALUES (?,?,?,?,?,?)`)
    .run(b.name, type, b.cloud_phone_id || null, b.cloud_token || null, b.cloud_lang || 'en_US', cap);
  const acc = db.prepare(`SELECT * FROM accounts WHERE id=?`).get(info.lastInsertRowid);
  bootAccount(acc);
  res.json({ id: acc.id });
});

app.post('/api/accounts/:id/cap', (req, res) => {
  const cap = Math.max(0, parseInt((req.body || {}).daily_cap) || 0);
  db.prepare(`UPDATE accounts SET daily_cap=? WHERE id=?`).run(cap, +req.params.id);
  res.json({ ok: true });
});

app.post('/api/accounts/:id/delete', async (req, res) => {
  const id = +req.params.id;
  db.prepare(`DELETE FROM messages WHERE account_id=?`).run(id);
  db.prepare(`DELETE FROM campaigns WHERE account_id=?`).run(id);
  db.prepare(`DELETE FROM accounts WHERE id=?`).run(id);
  await destroyAccount(id);
  res.json({ ok: true });
});

app.post('/api/accounts/:id/logout', async (req, res) => {
  const rec = accounts.get(+req.params.id);
  if (rec?.client) { try { await rec.client.logout(); } catch (_) {} rec.state = 'disconnected'; rec.info = null; }
  res.json({ ok: true });
});

// --- settings (OpenAI + opt-out + defaults + panel password) -----------
const DEF_KEYS = ['def_min_delay', 'def_max_delay', 'def_daily_limit', 'def_batch_size',
  'def_batch_pause', 'def_active_from', 'def_active_to', 'def_human_typing', 'def_natural_timing', 'def_micro_breaks'];
const sha = (s) => crypto.createHash('sha256').update(String(s)).digest('hex');

app.get('/api/settings', (req, res) => {
  const out = {
    openai_model: getSetting('openai_model', 'gpt-4o-mini'),
    openai_set: !!getSetting('openai_api_key'),
    optout_keywords: getSetting('optout_keywords', ''),
    optout_reply: getSetting('optout_reply', ''),
    panel_password_set: !!getSetting('panel_password_hash'),
  };
  for (const k of DEF_KEYS) out[k] = getSetting(k, '');
  res.json(out);
});
app.post('/api/settings', (req, res) => {
  const b = req.body || {};
  if (typeof b.openai_api_key === 'string' && b.openai_api_key.trim() !== '') setSetting('openai_api_key', b.openai_api_key.trim());
  if (b.openai_model) setSetting('openai_model', b.openai_model);
  if (typeof b.optout_keywords === 'string') setSetting('optout_keywords', b.optout_keywords);
  if (typeof b.optout_reply === 'string') setSetting('optout_reply', b.optout_reply);
  for (const k of DEF_KEYS) if (b[k] !== undefined && b[k] !== '') setSetting(k, String(b[k]));
  // panel password: non-empty sets it; explicit clear flag removes it
  if (typeof b.panel_password === 'string' && b.panel_password.trim() !== '') setSetting('panel_password_hash', sha(b.panel_password.trim()));
  if (b.panel_password_clear) setSetting('panel_password_hash', '');
  res.json({ ok: true });
});

app.post('/api/auth/check', (req, res) => {
  const hash = getSetting('panel_password_hash');
  if (!hash) return res.json({ ok: true });            // no password set
  res.json({ ok: sha((req.body || {}).password || '') === hash });
});

// --- AI assist ----------------------------------------------------------
app.post('/api/ai/variations', async (req, res) => {
  const key = getSetting('openai_api_key');
  if (!key) return res.status(400).json({ error: 'No OpenAI key set (Settings page)' });
  try {
    const v = await ai.variations(key, getSetting('openai_model', 'gpt-4o-mini'),
      req.body.draft || '', +req.body.count || 5, req.body.tone || 'friendly');
    res.json({ variations: v });
  } catch (e) { res.status(500).json({ error: String(e.message || e) }); }
});

// --- preview ------------------------------------------------------------
app.post('/api/preview', (req, res) => {
  const variants = Array.isArray(req.body.variants) ? req.body.variants.filter(Boolean) : [];
  if (!variants.length) return res.json({ samples: [] });
  const contacts = db.prepare(`SELECT * FROM contacts LIMIT 3`).all();
  const sample = contacts.length ? contacts : [{ name: 'Aisha', phone: '14155550123', fields: '{"company":"Acme Corp"}' }];
  const samples = sample.map(c => {
    const v = variants[Math.floor(Math.random() * variants.length)];
    return { to: c.name || c.phone, text: render(v, c) };
  });
  res.json({ samples });
});

// --- test send ----------------------------------------------------------
app.post('/api/test', async (req, res) => {
  const rec = accounts.get(+req.body.account_id);
  if (!rec) return res.status(400).json({ error: 'Account not found' });
  if (rec.state !== 'ready') return res.status(400).json({ error: 'Account not connected' });
  const phone = String(req.body.phone || '').replace(/[^\d]/g, '');
  const text = render(String(req.body.message || ''), { name: 'there', phone, fields: '{}' });
  if (!phone || !text) return res.status(400).json({ error: 'Phone and message required' });
  try {
    if (rec.type === 'cloud_api') {
      const acc = db.prepare(`SELECT * FROM accounts WHERE id=?`).get(rec.id);
      await cloudapi.send(acc, { cloud_template: null }, phone, text);
    } else {
      const numberId = await rec.client.getNumberId(phone);
      if (!numberId) return res.status(400).json({ error: 'That number is not on WhatsApp' });
      await rec.client.sendMessage(numberId._serialized, text);
    }
    res.json({ ok: true });
  } catch (e) { res.status(500).json({ error: String(e.message || e) }); }
});

// --- contacts -----------------------------------------------------------
function normalizePhone(raw) {
  if (raw == null) return '';
  return String(raw).trim().replace(/[^\d]/g, '');
}

app.post('/api/contacts/import', upload.single('file'), (req, res) => {
  if (!req.file) return res.status(400).json({ error: 'No file uploaded' });
  try {
    const wb = XLSX.readFile(req.file.path);
    const rows = XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { defval: '' });
    fs.unlink(req.file.path, () => {});
    if (!rows.length) return res.json({ imported: 0, skipped: 0 });

    const cols = Object.keys(rows[0]);
    const phoneCol = cols.find(c => /^(phone|mobile|number|whatsapp|cell|msisdn)/i.test(c)) || cols[0];
    const nameCol = cols.find(c => /^(name|full ?name|contact)/i.test(c));

    // optional: add the imported contacts to a (new or existing) list
    let listId = null;
    const listName = String(req.body.list || '').trim();
    if (listName) {
      db.prepare(`INSERT OR IGNORE INTO lists (name) VALUES (?)`).run(listName);
      listId = db.prepare(`SELECT id FROM lists WHERE name=?`).get(listName).id;
    }

    const insert = db.prepare(`INSERT INTO contacts (name, phone, fields) VALUES (?, ?, ?)`);
    const link = db.prepare(`INSERT OR IGNORE INTO contact_list (contact_id, list_id) VALUES (?, ?)`);
    let imported = 0, skipped = 0;
    db.transaction(() => {
      for (const row of rows) {
        const phone = normalizePhone(row[phoneCol]);
        if (!phone || phone.length < 7) { skipped++; continue; }
        const name = nameCol ? String(row[nameCol] || '').trim() : '';
        const fields = {};
        for (const c of cols) { if (c !== phoneCol && c !== nameCol) fields[c] = row[c]; }
        const info = insert.run(name, phone, JSON.stringify(fields));
        if (listId) link.run(info.lastInsertRowid, listId);
        imported++;
      }
    })();
    res.json({ imported, skipped, columns: cols, phoneColumn: phoneCol, nameColumn: nameCol || null, list: listName || null });
  } catch (e) { res.status(500).json({ error: String(e.message || e) }); }
});

app.get('/api/contacts', (req, res) => {
  const listId = req.query.list_id ? +req.query.list_id : null;
  const inList = listId ? `WHERE id IN (SELECT contact_id FROM contact_list WHERE list_id=${listId})` : '';
  const total = db.prepare(`SELECT COUNT(*) c FROM contacts ${inList}`).get().c;
  const unsubscribed = db.prepare(`SELECT COUNT(*) c FROM contacts WHERE unsubscribed=1`).get().c;
  const rows = db.prepare(`SELECT id, name, phone, fields, unsubscribed FROM contacts ${inList} ORDER BY id DESC LIMIT 200`).all();
  res.json({ total, unsubscribed, rows });
});
app.delete('/api/contacts', (req, res) => {
  db.prepare(`DELETE FROM contacts`).run();
  db.prepare(`DELETE FROM contact_list`).run();
  res.json({ ok: true });
});

// --- lists --------------------------------------------------------------
app.get('/api/lists', (req, res) => {
  const rows = db.prepare(`
    SELECT l.id, l.name,
      (SELECT COUNT(*) FROM contact_list cl WHERE cl.list_id = l.id) AS count
    FROM lists l ORDER BY l.name`).all();
  res.json({ rows });
});
app.post('/api/lists', (req, res) => {
  const name = String((req.body || {}).name || '').trim();
  if (!name) return res.status(400).json({ error: 'Name required' });
  db.prepare(`INSERT OR IGNORE INTO lists (name) VALUES (?)`).run(name);
  res.json({ ok: true });
});
app.post('/api/lists/:id/delete', (req, res) => {
  const id = +req.params.id;
  db.prepare(`DELETE FROM contact_list WHERE list_id=?`).run(id);   // contacts remain
  db.prepare(`DELETE FROM lists WHERE id=?`).run(id);
  res.json({ ok: true });
});

// --- opt-outs -----------------------------------------------------------
app.get('/api/optouts', (req, res) => {
  const total = db.prepare(`SELECT COUNT(*) c FROM optouts`).get().c;
  const rows = db.prepare(`SELECT phone, keyword, created_at FROM optouts ORDER BY created_at DESC LIMIT 500`).all();
  res.json({ total, rows });
});
app.post('/api/optouts', (req, res) => {
  const phone = String((req.body || {}).phone || '').replace(/\D/g, '');
  if (!phone) return res.status(400).json({ error: 'Phone required' });
  db.prepare(`INSERT OR IGNORE INTO optouts (phone, keyword) VALUES (?, 'manual')`).run(phone);
  db.prepare(`UPDATE contacts SET unsubscribed=1 WHERE phone=?`).run(phone);
  res.json({ ok: true });
});
app.post('/api/optouts/delete', (req, res) => {
  const phone = String((req.body || {}).phone || '').replace(/\D/g, '');
  db.prepare(`DELETE FROM optouts WHERE phone=?`).run(phone);
  db.prepare(`UPDATE contacts SET unsubscribed=0 WHERE phone=?`).run(phone);
  res.json({ ok: true });
});

// --- campaigns ----------------------------------------------------------
app.post('/api/campaigns', upload.single('media'), (req, res) => {
  try {
    const b = req.body;
    if (!b.name || !b.account_id) return res.status(400).json({ error: 'Name and account required' });
    let variants = [];
    try { variants = JSON.parse(b.variants || '[]'); } catch (_) {}
    variants = variants.map(s => String(s).trim()).filter(Boolean);
    if (!variants.length) return res.status(400).json({ error: 'At least one message is required' });

    let mediaPath = null, mediaName = null;
    if (req.file) {
      const safe = path.join(UPLOAD_DIR, Date.now() + '_' + req.file.originalname.replace(/[^\w.\-]/g, '_'));
      fs.renameSync(req.file.path, safe);
      mediaPath = safe; mediaName = req.file.originalname;
    }

    const listId = b.list_id ? +b.list_id : null;

    const info = db.prepare(`
      INSERT INTO campaigns
        (account_id, name, variants, media_path, media_name, status,
         min_delay, max_delay, daily_limit, batch_size, batch_pause,
         active_from, active_to, human_typing, natural_timing, micro_breaks, cloud_template, list_id)
      VALUES (?,?,?,?,?, 'draft', ?,?,?,?,?, ?,?,?,?,?, ?,?)
    `).run(
      +b.account_id, b.name, JSON.stringify(variants), mediaPath, mediaName,
      parseInt(b.min_delay) || 20, parseInt(b.max_delay) || 60,
      parseInt(b.daily_limit) || 0, parseInt(b.batch_size) || 0, parseInt(b.batch_pause) || 0,
      hourOrAlways(b.active_from), hourOrAlways(b.active_to),
      b.human_typing ? 1 : 0, b.natural_timing ? 1 : 0, b.micro_breaks ? 1 : 0,
      b.cloud_template || null, listId,
    );
    const campaignId = info.lastInsertRowid;

    // Audience: a chosen list (or all), excluding unsubscribed + opt-outs.
    let q = `SELECT * FROM contacts WHERE unsubscribed=0 AND phone NOT IN (SELECT phone FROM optouts)`;
    if (listId) q += ` AND id IN (SELECT contact_id FROM contact_list WHERE list_id=${listId})`;
    const contacts = db.prepare(q).all();

    // Enqueue: each contact gets a random variant, rendered now.
    const enq = db.prepare(`INSERT INTO messages (campaign_id, account_id, contact_id, phone, name, rendered) VALUES (?,?,?,?,?,?)`);
    db.transaction(() => {
      for (const c of contacts) {
        const v = variants[Math.floor(Math.random() * variants.length)];
        enq.run(campaignId, +b.account_id, c.id, c.phone, c.name, render(v, c));
      }
    })();

    res.json({ id: campaignId, queued: contacts.length });
  } catch (e) { res.status(500).json({ error: String(e.message || e) }); }
});

function progress(id) {
  return db.prepare(`
    SELECT COUNT(*) total,
      SUM(status='sent') sent, SUM(status='failed') failed,
      SUM(status='invalid') invalid, SUM(status='pending') pending
    FROM messages WHERE campaign_id=?`).get(id);
}

app.get('/api/campaigns', (req, res) => {
  const rows = db.prepare(`
    SELECT c.*, a.name AS account_name, a.type AS account_type
    FROM campaigns c LEFT JOIN accounts a ON a.id=c.account_id ORDER BY c.id DESC`).all();
  for (const r of rows) r.progress = progress(r.id);
  res.json({ rows });
});

app.get('/api/campaigns/:id', (req, res) => {
  const c = db.prepare(`
    SELECT c.*, a.name AS account_name, a.type AS account_type
    FROM campaigns c LEFT JOIN accounts a ON a.id=c.account_id WHERE c.id=?`).get(req.params.id);
  if (!c) return res.status(404).json({ error: 'Not found' });
  c.progress = progress(c.id);
  c.messages = db.prepare(`SELECT id, phone, name, status, error, sent_at FROM messages WHERE campaign_id=? ORDER BY id DESC LIMIT 300`).all(c.id);
  res.json(c);
});

app.post('/api/campaigns/:id/:action', (req, res) => {
  const { id, action } = req.params;
  const c = db.prepare(`SELECT * FROM campaigns WHERE id=?`).get(id);
  if (!c) return res.status(404).json({ error: 'Not found' });
  if (action === 'start') {
    // one running campaign per account; pause others on the same number
    db.prepare(`UPDATE campaigns SET status='paused' WHERE status='running' AND account_id=? AND id<>?`).run(c.account_id, id);
    db.prepare(`UPDATE campaigns SET status='running', scheduled_at=NULL WHERE id=?`).run(id);
    const rec = accounts.get(c.account_id); if (rec) rec.sentSinceBreak = 0;
  } else if (action === 'pause') {
    db.prepare(`UPDATE campaigns SET status='paused' WHERE id=?`).run(id);
  } else if (action === 'schedule') {
    let when = String((req.body || {}).scheduled_at || '').trim().replace('T', ' ');
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(when)) when += ':00';
    if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(when)) return res.status(400).json({ error: 'Invalid date/time' });
    db.prepare(`UPDATE campaigns SET status='scheduled', scheduled_at=? WHERE id=?`).run(when, id);
  } else if (action === 'unschedule') {
    db.prepare(`UPDATE campaigns SET status='draft', scheduled_at=NULL WHERE id=?`).run(id);
  } else if (action === 'retry') {
    // requeue failed (and optionally invalid) messages, then pause so the user starts it
    const cols = req.body && req.body.include_invalid ? `('failed','invalid')` : `('failed')`;
    const r = db.prepare(`UPDATE messages SET status='pending', error=NULL, sent_at=NULL WHERE campaign_id=? AND status IN ${cols}`).run(id);
    if (c.status === 'done') db.prepare(`UPDATE campaigns SET status='paused' WHERE id=?`).run(id);
    return res.json({ ok: true, requeued: r.changes });
  } else if (action === 'delete') {
    db.prepare(`DELETE FROM messages WHERE campaign_id=?`).run(id);
    db.prepare(`DELETE FROM campaigns WHERE id=?`).run(id);
  } else return res.status(400).json({ error: 'Unknown action' });
  res.json({ ok: true });
});

// Excel export of a campaign's per-message results (opened directly by the browser)
app.get('/api/campaigns/:id/export.xlsx', (req, res) => {
  const c = db.prepare(`SELECT name FROM campaigns WHERE id=?`).get(req.params.id);
  if (!c) return res.status(404).send('Not found');
  const rows = db.prepare(`
    SELECT phone AS Phone, name AS Name, status AS Status, error AS Error,
           sent_at AS "Sent at", rendered AS Message
    FROM messages WHERE campaign_id=? ORDER BY id`).all(req.params.id);
  const ws = XLSX.utils.json_to_sheet(rows.length ? rows
    : [{ Phone: '', Name: '', Status: '', Error: '', 'Sent at': '', Message: '' }]);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Results');
  const buf = XLSX.write(wb, { type: 'buffer', bookType: 'xlsx' });
  res.setHeader('Content-Disposition', `attachment; filename="campaign-${req.params.id}-results.xlsx"`);
  res.setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  res.send(buf);
});

// --- version / auto-update ---------------------------------------------
function semverGt(a, b) {
  const pa = String(a).split('.').map(Number), pb = String(b).split('.').map(Number);
  for (let i = 0; i < 3; i++) { if ((pa[i] || 0) > (pb[i] || 0)) return true; if ((pa[i] || 0) < (pb[i] || 0)) return false; }
  return false;
}
let updateAssetUrl = null; // remembered from the last successful version check

app.get('/api/version', async (req, res) => {
  const out = { current: APP_VERSION, latest: null, updateAvailable: false, url: null, error: null };
  try {
    const r = await fetch(`https://api.github.com/repos/${REPO}/releases/latest`,
      { headers: { 'User-Agent': 'BulkWPSender', Accept: 'application/vnd.github+json' } });
    if (!r.ok) throw new Error('GitHub ' + r.status);
    const d = await r.json();
    out.latest = String(d.tag_name || '').replace(/^v/, '');
    out.url = d.html_url;
    const asset = (d.assets || []).find(a => /\.exe$/i.test(a.name));
    updateAssetUrl = asset ? asset.browser_download_url : null;
    out.updateAvailable = !!out.latest && semverGt(out.latest, APP_VERSION);
  } catch (e) { out.error = String(e.message || e); }
  res.json(out);
});

app.post('/api/update', async (req, res) => {
  if (process.platform !== 'win32')
    return res.status(400).json({ error: 'Auto-update runs on Windows only — download the installer from the release page.' });
  const url = (req.body && req.body.assetUrl) || updateAssetUrl;
  if (!url) return res.status(400).json({ error: 'No installer found. Check for updates first, then retry.' });
  try {
    const r = await fetch(url, { headers: { 'User-Agent': 'BulkWPSender' } });
    if (!r.ok) throw new Error('download ' + r.status);
    const installer = path.join(os.tmpdir(), 'BulkWPSender-Setup.exe');
    fs.writeFileSync(installer, Buffer.from(await r.arrayBuffer()));

    // Clean self-update: a separate PowerShell process stops THIS app (only our
    // own node/php under the install dir — never the user's other node apps),
    // installs silently (which preserves data\), then relaunches the tray.
    const appRoot = path.join(__dirname, '..');
    const updater = path.join(os.tmpdir(), 'bwps-update.ps1');
    const ps = `
$root = ${JSON.stringify(appRoot)}
$installer = ${JSON.stringify(installer)}
Start-Sleep -Seconds 2
Get-CimInstance Win32_Process | Where-Object { $_.ExecutablePath -and $_.ExecutablePath.StartsWith($root) -and ($_.Name -eq 'node.exe' -or $_.Name -eq 'php.exe') } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 1
Start-Process -FilePath $installer -ArgumentList '/VERYSILENT','/SUPPRESSMSGBOXES','/NORESTART' -Wait
Start-Sleep -Seconds 2
Start-Process -FilePath 'powershell' -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',(Join-Path $root 'tray.ps1')
`;
    fs.writeFileSync(updater, ps);
    spawn('powershell', ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', updater],
      { detached: true, stdio: 'ignore' }).unref();
    console.log('[update] updater launched; this engine will exit for the in-place update');
    res.json({ ok: true });
  } catch (e) { res.status(500).json({ error: String(e.message || e) }); }
});

// Bind to loopback only — never expose the API to the local network.
const server = app.listen(PORT, '127.0.0.1', () => {
  booted = true;
  console.log(`[api] BulkWPSender engine v${APP_VERSION} on http://127.0.0.1:${PORT}`);
});
server.on('error', (e) => {
  console.error('[FATAL] Could not bind port ' + PORT + ':', e && e.message ? e.message : e);
  if (e && e.code === 'EADDRINUSE') console.error('[FATAL] Port ' + PORT + ' is already in use (another copy running?).');
  process.exit(1);
});
