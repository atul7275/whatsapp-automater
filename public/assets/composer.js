// Client-side logic for the campaign composer. Talks to the Node engine
// directly (CORS is enabled there) for AI generation, preview and test sends.
const ENGINE = window.ENGINE;
const $ = (id) => document.getElementById(id);

// ---- variant textareas ----------------------------------------------------
function variantRow(value = '') {
  const wrap = document.createElement('div');
  wrap.className = 'variant';
  const ta = document.createElement('textarea');
  ta.rows = 3; ta.value = value; ta.placeholder = '{Hi|Hello} {{name}}! ...';
  const del = document.createElement('button');
  del.type = 'button'; del.className = 'btn ghost small'; del.textContent = '✕';
  del.onclick = () => wrap.remove();
  wrap.append(ta, del);
  return wrap;
}
function addVariant(value = '') { $('variants').appendChild(variantRow(value)); }
function getVariants() {
  return [...document.querySelectorAll('#variants textarea')].map(t => t.value.trim()).filter(Boolean);
}

// insert placeholder/spintax into the last focused or first textarea
let lastTA = null;
document.addEventListener('focusin', (e) => { if (e.target.tagName === 'TEXTAREA') lastTA = e.target; });
document.querySelectorAll('[data-ins]').forEach(b => b.onclick = () => {
  const ta = lastTA || document.querySelector('#variants textarea');
  if (!ta) return;
  const s = ta.selectionStart ?? ta.value.length;
  ta.value = ta.value.slice(0, s) + b.dataset.ins + ta.value.slice(ta.selectionEnd ?? s);
  ta.focus();
});

// ---- AI generation --------------------------------------------------------
async function aiGen() {
  const draft = $('aiDraft').value.trim() || (getVariants()[0] || '');
  if (!draft) { $('aiStatus').textContent = 'Enter an idea or a first variant.'; return; }
  $('aiBtn').disabled = true; $('aiStatus').textContent = 'Generating…';
  try {
    const r = await fetch(ENGINE + '/api/ai/variations', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ draft, count: +$('aiCount').value, tone: $('aiTone').value }),
    });
    const data = await r.json();
    if (!r.ok) throw new Error(data.error || 'failed');
    (data.variations || []).forEach(v => addVariant(v));
    $('aiStatus').textContent = `Added ${data.variations.length} variations.`;
  } catch (e) { $('aiStatus').textContent = 'Error: ' + e.message; }
  $('aiBtn').disabled = false;
}

// ---- preview --------------------------------------------------------------
async function previewMsg() {
  const variants = getVariants();
  const box = $('previewBox');
  if (!variants.length) { box.innerHTML = ''; return; }
  const r = await fetch(ENGINE + '/api/preview', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ variants }),
  });
  const data = await r.json();
  box.innerHTML = '<div class="card"><h2>Preview</h2>' +
    (data.samples || []).map(s =>
      `<div class="bubble"><strong>${escapeHtml(s.to)}</strong><br>${escapeHtml(s.text).replace(/\n/g,'<br>')}</div>`
    ).join('') + '</div>';
}

// ---- test send ------------------------------------------------------------
async function sendTest() {
  const variants = getVariants();
  const phone = $('testPhone').value.trim();
  if (!phone || !variants.length) { $('testStatus').textContent = 'Need a number and at least one variant.'; return; }
  $('testStatus').textContent = 'Sending…';
  try {
    const r = await fetch(ENGINE + '/api/test', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ account_id: +$('accountSel').value, phone, message: variants[0] }),
    });
    const data = await r.json();
    $('testStatus').textContent = r.ok ? '✓ Sent — check the phone.' : 'Error: ' + (data.error || 'failed');
  } catch (e) { $('testStatus').textContent = 'Error: ' + e.message; }
}

function escapeHtml(s) { return String(s).replace(/[&<>"]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c])); }

// ---- account-type-aware UI ------------------------------------------------
function syncAccountUI() {
  const opt = $('accountSel').selectedOptions[0];
  const isCloud = opt && opt.dataset.type === 'cloud_api';
  $('cloudTplWrap').style.display = isCloud ? 'block' : 'none';
  const dl = $('dailyLimit');
  if (isCloud) { dl.max = ''; $('capNote').textContent = 'Business API: limited by your Meta tier, not by this tool.'; }
  else { dl.max = 50; if (+dl.value > 50 || +dl.value === 0) dl.value = 50; $('capNote').textContent = 'Automation is hard-capped at 50/day per number.'; }
}

// ---- submit: pack variants into the hidden field --------------------------
$('campForm').addEventListener('submit', (e) => {
  const variants = getVariants();
  if (!variants.length) { e.preventDefault(); alert('Add at least one message variant.'); return; }
  $('variantsField').value = JSON.stringify(variants);
});

// init
$('accountSel').addEventListener('change', syncAccountUI);
addVariant();
syncAccountUI();
