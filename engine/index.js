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
const express = require('express');
const cors = require('cors');
const multer = require('multer');
const qrcode = require('qrcode');
const path = require('path');
const fs = require('fs');
const XLSX = require('xlsx');
const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');

const { db, getSetting, setSetting } = require('./db');
const { render } = require('./template');
const cloudapi = require('./cloudapi');
const ai = require('./ai');

const PORT = process.env.PORT || 3000;
const DATA = path.join(__dirname, '..', 'data');
const SESSION_DIR = path.join(DATA, 'session');
const UPLOAD_DIR = path.join(DATA, 'uploads');
fs.mkdirSync(UPLOAD_DIR, { recursive: true });
fs.mkdirSync(SESSION_DIR, { recursive: true });

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
  if (rec.type === 'automation') {
    const lim = campaign.daily_limit > 0 ? campaign.daily_limit : AUTOMATION_DAILY_CAP;
    return Math.min(lim, AUTOMATION_DAILY_CAP);
  }
  return campaign.daily_limit; // cloud api: respect user's number (0 = unlimited)
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

// master tick: drive every ready, idle account independently (parallel flows)
setInterval(() => {
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
  const rows = db.prepare(`SELECT id, name, type, cloud_phone_id, cloud_lang, created_at FROM accounts ORDER BY id`).all();
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

  const info = db.prepare(`INSERT INTO accounts (name, type, cloud_phone_id, cloud_token, cloud_lang) VALUES (?,?,?,?,?)`)
    .run(b.name, type, b.cloud_phone_id || null, b.cloud_token || null, b.cloud_lang || 'en_US');
  const acc = db.prepare(`SELECT * FROM accounts WHERE id=?`).get(info.lastInsertRowid);
  bootAccount(acc);
  res.json({ id: acc.id });
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

// --- settings (OpenAI) --------------------------------------------------
app.get('/api/settings', (req, res) => {
  res.json({
    openai_model: getSetting('openai_model', 'gpt-4o-mini'),
    openai_set: !!getSetting('openai_api_key'),
  });
});
app.post('/api/settings', (req, res) => {
  const b = req.body || {};
  if (typeof b.openai_api_key === 'string' && b.openai_api_key !== '') setSetting('openai_api_key', b.openai_api_key);
  if (b.openai_api_key === '') setSetting('openai_api_key', '');
  if (b.openai_model) setSetting('openai_model', b.openai_model);
  res.json({ ok: true });
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

    const insert = db.prepare(`INSERT INTO contacts (name, phone, fields) VALUES (?, ?, ?)`);
    let imported = 0, skipped = 0;
    db.transaction(() => {
      for (const row of rows) {
        const phone = normalizePhone(row[phoneCol]);
        if (!phone || phone.length < 7) { skipped++; continue; }
        const name = nameCol ? String(row[nameCol] || '').trim() : '';
        const fields = {};
        for (const c of cols) { if (c !== phoneCol && c !== nameCol) fields[c] = row[c]; }
        insert.run(name, phone, JSON.stringify(fields));
        imported++;
      }
    })();
    res.json({ imported, skipped, columns: cols, phoneColumn: phoneCol, nameColumn: nameCol || null });
  } catch (e) { res.status(500).json({ error: String(e.message || e) }); }
});

app.get('/api/contacts', (req, res) => {
  const total = db.prepare(`SELECT COUNT(*) c FROM contacts`).get().c;
  const rows = db.prepare(`SELECT id, name, phone, fields FROM contacts ORDER BY id DESC LIMIT 200`).all();
  res.json({ total, rows });
});
app.delete('/api/contacts', (req, res) => { db.prepare(`DELETE FROM contacts`).run(); res.json({ ok: true }); });

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

    const info = db.prepare(`
      INSERT INTO campaigns
        (account_id, name, variants, media_path, media_name, status,
         min_delay, max_delay, daily_limit, batch_size, batch_pause,
         active_from, active_to, human_typing, natural_timing, micro_breaks, cloud_template)
      VALUES (?,?,?,?,?, 'draft', ?,?,?,?,?, ?,?,?,?,?, ?)
    `).run(
      +b.account_id, b.name, JSON.stringify(variants), mediaPath, mediaName,
      parseInt(b.min_delay) || 20, parseInt(b.max_delay) || 60,
      parseInt(b.daily_limit) || 0, parseInt(b.batch_size) || 0, parseInt(b.batch_pause) || 0,
      hourOrAlways(b.active_from), hourOrAlways(b.active_to),
      b.human_typing ? 1 : 0, b.natural_timing ? 1 : 0, b.micro_breaks ? 1 : 0,
      b.cloud_template || null,
    );
    const campaignId = info.lastInsertRowid;

    // Enqueue: each contact gets a random variant, rendered now.
    const contacts = db.prepare(`SELECT * FROM contacts`).all();
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
    db.prepare(`UPDATE campaigns SET status='running' WHERE id=?`).run(id);
    const rec = accounts.get(c.account_id); if (rec) rec.sentSinceBreak = 0;
  } else if (action === 'pause') {
    db.prepare(`UPDATE campaigns SET status='paused' WHERE id=?`).run(id);
  } else if (action === 'delete') {
    db.prepare(`DELETE FROM messages WHERE campaign_id=?`).run(id);
    db.prepare(`DELETE FROM campaigns WHERE id=?`).run(id);
  } else return res.status(400).json({ error: 'Unknown action' });
  res.json({ ok: true });
});

// Bind to loopback only — never expose the API to the local network.
app.listen(PORT, '127.0.0.1', () => console.log(`[api] BulkWPSender engine on http://localhost:${PORT}`));
