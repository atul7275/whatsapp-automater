// WhatsApp Business Cloud API sender (official Meta Graph API).
// Used by accounts of type 'cloud_api'. Requires a phone number ID and a
// permanent/temporary access token from the Meta developer dashboard.
//
// Note: outside the 24h customer-service window, Meta only allows pre-approved
// *template* messages. If a campaign sets cloud_template we send a template;
// otherwise we send a plain text message (works inside the 24h window).

const GRAPH = 'https://graph.facebook.com/v21.0';

async function verify(account) {
  const res = await fetch(`${GRAPH}/${account.cloud_phone_id}?fields=display_phone_number,verified_name`, {
    headers: { Authorization: `Bearer ${account.cloud_token}` },
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error?.message || 'Verification failed');
  return data; // { display_phone_number, verified_name, id }
}

async function send(account, campaign, to, renderedText) {
  let payload;
  if (campaign.cloud_template) {
    payload = {
      messaging_product: 'whatsapp',
      to,
      type: 'template',
      template: { name: campaign.cloud_template, language: { code: account.cloud_lang || 'en_US' } },
    };
  } else {
    payload = {
      messaging_product: 'whatsapp',
      to,
      type: 'text',
      text: { preview_url: true, body: renderedText },
    };
  }
  const res = await fetch(`${GRAPH}/${account.cloud_phone_id}/messages`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${account.cloud_token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.error?.message || JSON.stringify(data));
  return data;
}

module.exports = { verify, send };
