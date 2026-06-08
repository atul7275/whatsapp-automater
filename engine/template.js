// Message rendering: personalization + spintax.
//
//  Personalization:  {{name}}, {{company}}, {{any_csv_column}}
//  Spintax:          {Hi|Hello|Hey} -> one option picked at random per message
//
// Personalization runs first, then spintax, so a column value containing a
// "{a|b}" would also be spun (intentional, advanced users can exploit it).

function pick(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

// Replace the innermost {a|b|c} groups repeatedly until none remain (supports nesting).
function spin(text) {
  const re = /\{([^{}]*\|[^{}]*)\}/; // a {...} group that contains at least one '|'
  let out = text;
  let guard = 0;
  while (re.test(out) && guard < 100) {
    out = out.replace(re, (_, group) => pick(group.split('|')));
    guard++;
  }
  return out;
}

function personalize(template, contact) {
  let fields = {};
  try { fields = JSON.parse(contact.fields || '{}'); } catch (_) {}
  return template.replace(/\{\{\s*([\w. -]+?)\s*\}\}/g, (_, key) => {
    const k = String(key).trim().toLowerCase();
    if (k === 'name') return contact.name || '';
    if (k === 'phone') return contact.phone || '';
    // case-insensitive lookup of extra columns
    for (const fk of Object.keys(fields)) {
      if (fk.toLowerCase() === k) return fields[fk] ?? '';
    }
    return '';
  });
}

function render(template, contact) {
  return spin(personalize(template, contact)).trim();
}

module.exports = { render, spin, personalize };
