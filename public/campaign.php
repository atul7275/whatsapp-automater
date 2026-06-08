<?php
require __DIR__ . '/inc.php';

$id = (int)($_GET['id'] ?? 0);
$act = $_POST['action'] ?? '';
if ($act && in_array($act, ['start', 'pause', 'delete'], true)) {
    $r = api_post("/api/campaigns/$id/$act");
    if ($act === 'delete') { header('Location: campaigns.php'); exit; }
    header("Location: campaign.php?id=$id"); exit;
}

$c = api_get("/api/campaigns/$id");
layout_head('Campaign');

if (isset($c['__error']) || isset($c['error'])) {
    flash('Not found: ' . ($c['error'] ?? $c['__error']), 'err');
    layout_foot(); exit;
}
$p = $c['progress'];
$done = (int)$p['sent'] + (int)$p['failed'] + (int)$p['invalid'];
$pct = $p['total'] > 0 ? round($done / $p['total'] * 100) : 0;
?>
<h1><?= h($c['name']) ?> <span class="pill <?= h($c['status']) ?>"><?= h($c['status']) ?></span></h1>
<p><a href="campaigns.php">← All campaigns</a></p>

<div class="grid">
  <div class="card">
    <h2>Controls</h2>
    <div class="btnrow">
      <?php if ($c['status'] !== 'running'): ?>
        <form method="post"><input type="hidden" name="action" value="start"><button class="btn">▶ Start</button></form>
      <?php else: ?>
        <form method="post"><input type="hidden" name="action" value="pause"><button class="btn warn">⏸ Pause</button></form>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('Delete this campaign and its queue?')">
        <input type="hidden" name="action" value="delete"><button class="btn danger">🗑 Delete</button>
      </form>
    </div>
    <p class="muted small">
      Account <strong><?= h($c['account_name']) ?></strong>
      (<?= $c['account_type']==='cloud_api' ? 'Business API' : 'humanized automation' ?>) ·
      <?= count(json_decode($c['variants'] ?? '[]', true) ?: []) ?> variant(s)
      <?= $c['media_name'] ? '· 📎 ' . h($c['media_name']) : '' ?>
    </p>
    <p class="muted small">Delay <?= (int)$c['min_delay'] ?>–<?= (int)$c['max_delay'] ?>s ·
      daily cap <?= (int)$c['daily_limit'] ?: '∞' ?> ·
      batch <?= (int)$c['batch_size'] ?: 'off' ?>/<?= (int)$c['batch_pause'] ?>min ·
      hours <?= $c['active_from']<0 ? 'always' : sprintf('%02d–%02d', $c['active_from'], $c['active_to']) ?> ·
      <?= $c['human_typing'] ? '⌨ typing ' : '' ?><?= $c['natural_timing'] ? '🎲 natural ' : '' ?><?= $c['micro_breaks'] ? '☕ breaks' : '' ?>
    </p>
  </div>

  <div class="card">
    <h2>Progress — <?= $pct ?>%</h2>
    <div class="bar"><div class="fill" style="width:<?= $pct ?>%"></div></div>
    <ul class="stats">
      <li>Total <strong><?= (int)$p['total'] ?></strong></li>
      <li class="ok">Sent <strong><?= (int)$p['sent'] ?></strong></li>
      <li class="warn">Pending <strong><?= (int)$p['pending'] ?></strong></li>
      <li class="err">Failed <strong><?= (int)$p['failed'] ?></strong></li>
      <li class="muted">Invalid <strong><?= (int)$p['invalid'] ?></strong></li>
    </ul>
  </div>
</div>

<div class="card">
  <h2>Message log (latest 300)</h2>
  <table>
    <thead><tr><th>Name</th><th>Phone</th><th>Status</th><th>When</th><th>Error</th></tr></thead>
    <tbody>
    <?php foreach ($c['messages'] as $m): ?>
      <tr>
        <td><?= h($m['name']) ?></td>
        <td><?= h($m['phone']) ?></td>
        <td><span class="pill <?= h($m['status']) ?>"><?= h($m['status']) ?></span></td>
        <td class="muted small"><?= h($m['sent_at']) ?></td>
        <td class="err small"><?= h($m['error']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($c['status'] === 'running'): ?>
<script>setTimeout(() => location.reload(), 5000);</script>
<?php endif; ?>
<?php layout_foot(); ?>
