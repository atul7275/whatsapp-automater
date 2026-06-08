<?php
require __DIR__ . '/inc.php';

$accounts = api_get('/api/accounts');
$campaigns = api_get('/api/campaigns');
$contacts = api_get('/api/contacts');
layout_head('Dashboard');

if (isset($accounts['__error'])) {
    flash('Cannot reach the engine. Start it with:  cd engine && npm start  — ' . $accounts['__error'], 'err');
    layout_foot(); exit;
}

$accs = $accounts['rows'] ?? [];
$ready = array_filter($accs, fn($a) => ($a['state'] ?? '') === 'ready');
$running = array_filter($campaigns['rows'] ?? [], fn($c) => $c['status'] === 'running');
?>
<h1>Dashboard</h1>

<div id="updateBanner"></div>

<div class="stats-row">
  <div class="stat"><span><?= count($accs) ?></span>Accounts</div>
  <div class="stat ok"><span><?= count($ready) ?></span>Connected</div>
  <div class="stat"><span><?= (int)($contacts['total'] ?? 0) ?></span>Contacts</div>
  <div class="stat warn"><span><?= count($running) ?></span>Running now</div>
</div>

<div class="card">
  <h2>Connected numbers</h2>
  <?php if (!$accs): ?>
    <p class="muted">No WhatsApp numbers yet. <a href="accounts.php">Add your first account →</a></p>
  <?php else: ?>
    <table>
      <thead><tr><th>Account</th><th>Type</th><th>Status</th><th>Sent today</th></tr></thead>
      <tbody>
      <?php foreach ($accs as $a): ?>
        <tr>
          <td><strong><?= h($a['name']) ?></strong>
            <?php if (!empty($a['info']['number'])): ?><br><span class="muted small"><?= h($a['info']['number']) ?></span><?php endif; ?></td>
          <td><?= $a['type'] === 'cloud_api' ? 'Business API' : 'Automation' ?></td>
          <td><span class="pill <?= h($a['state']) ?>"><?= h($a['state']) ?></span></td>
          <td><?= (int)$a['sent_today'] ?><?= $a['type'] === 'automation' ? ' / 50' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small" style="margin-top:10px"><a href="accounts.php">Manage accounts / scan QR →</a></p>
  <?php endif; ?>
</div>

<div class="note">
  <strong>⚠ Even humanized, this is automation.</strong> The 50/day cap per number,
  opt-in audiences, and slow warm-up are what actually keep numbers alive — humanization
  only reduces the rest of the risk. Use the <strong>Business API</strong> for serious volume.
</div>

<?php if (array_filter($accs, fn($a) => in_array($a['state'], ['qr','starting','authenticated']))): ?>
<script>setTimeout(() => location.reload(), 5000);</script>
<?php endif; ?>

<script>
// Check GitHub for a newer release and show a banner with one-click update.
(async () => {
  try {
    const r = await fetch(window.ENGINE + '/api/version');
    const v = await r.json();
    const el = document.getElementById('updateBanner');
    if (!el) return;
    if (v.updateAvailable) {
      el.innerHTML =
        `<div class="flash ok" style="display:flex;align-items:center;gap:12px;justify-content:space-between">
           <span>🎉 <strong>Update available:</strong> v${v.current} → <strong>v${v.latest}</strong></span>
           <span>
             <button class="btn small" id="updateNow">Update now</button>
             <a class="btn ghost small" href="${v.url}" target="_blank">Release notes</a>
           </span>
         </div>`;
      const btn = document.getElementById('updateNow');
      btn.onclick = async () => {
        if (!confirm('Download v' + v.latest + ' and run the installer now? The app will restart during the update.')) return;
        btn.disabled = true; btn.textContent = 'Downloading…';
        try {
          const rr = await fetch(window.ENGINE + '/api/update', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ assetUrl: null })
          });
          const dd = await rr.json();
          if (rr.ok) { btn.textContent = 'Installer launched — follow its prompts.'; }
          else { btn.disabled = false; btn.textContent = 'Update now'; alert('Update failed: ' + (dd.error || 'unknown') + '\n\nYou can download it manually from the release page.'); }
        } catch (e) { btn.disabled = false; btn.textContent = 'Update now'; alert('Update failed: ' + e.message); }
      };
    }
  } catch (_) { /* offline or engine down — no banner */ }
})();
</script>
<?php layout_foot(); ?>
