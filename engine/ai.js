// Optional AI assist (OpenAI) — rewrite a message draft into several natural,
// human-sounding variations. Placeholders like {{name}} are preserved so each
// recipient still gets personalized, but differently-worded, text.

async function variations(apiKey, model, draft, count = 5, tone = 'friendly') {
  const sys =
    'You rewrite WhatsApp messages into natural, human-sounding variations a real ' +
    'person would type. Keep them concise and conversational. CRITICAL RULES: ' +
    'preserve every placeholder exactly as written (e.g. {{name}}, {{company}}); ' +
    'do not add quotes or numbering; vary wording, greeting and sentence order so ' +
    'no two look templated. Return ONLY a JSON object: {"variations": ["...", "..."]}.';
  const user =
    `Tone: ${tone}. Produce ${count} variations of this WhatsApp message:\n\n${draft}`;

  const res = await fetch('https://api.openai.com/v1/chat/completions', {
    method: 'POST',
    headers: { Authorization: `Bearer ${apiKey}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      model: model || 'gpt-4o-mini',
      temperature: 0.95,
      response_format: { type: 'json_object' },
      messages: [{ role: 'system', content: sys }, { role: 'user', content: user }],
    }),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error?.message || 'OpenAI request failed');
  let out = [];
  try {
    out = JSON.parse(data.choices[0].message.content).variations || [];
  } catch (_) { /* fall through */ }
  return out.filter(s => typeof s === 'string' && s.trim()).map(s => s.trim());
}

module.exports = { variations };
