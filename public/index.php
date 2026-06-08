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
<?php layout_foot(); ?>
