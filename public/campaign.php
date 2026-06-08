<?php
require __DIR__ . '/inc.php';

$id = (int)($_GET['id'] ?? 0);
if (isset($_GET['dup'])) {
    $r = api_post("/api/campaigns/$id/duplicate");
    header('Location: campaign.php?id=' . ($r['id'] ?? $id)); exit;
}
$act = $_POST['action'] ?? '';
if ($act === 'schedule') {
    api_post_json("/api/campaigns/$id/schedule", ['scheduled_at' => $_POST['scheduled_at'] ?? '']);
    header("Location: campaign.php?id=$id"); exit;
} elseif ($act && in_array($act, ['start', 'pause', 'delete', 'unschedule', 'retry'], true)) {
    api_post("/api/campaigns/$id/$act");
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
        <form method="post"><input type="hidden" name="action" value="start"><button class="btn">▶ Start now</button></form>
      <?php else: ?>
        <form method="post"><input type="hidden" name="action" value="pause"><button class="btn warn">⏸ Pause</button></form>
      <?php endif; ?>
      <?php if ((int)$p['failed'] > 0): ?>
        <form method="post"><input type="hidden" name="action" value="retry"><button class="btn ghost">↻ Retry failed (<?= (int)$p['failed'] ?>)</button></form>
      <?php endif; ?>
      <?php if ($c['status'] !== 'running'): ?>
        <a class="btn ghost" href="campaign_edit.php?id=<?= $id ?>">✎ Edit</a>
      <?php endif; ?>
      <a class="btn ghost" href="campaign.php?id=<?= $id ?>&dup=1">⧉ Clone</a>
      <a class="btn ghost" href="<?= h(ENGINE) ?>/api/campaigns/<?= $id ?>/export.xlsx">⬇ Export results</a>
      <form method="post" onsubmit="return confirm('Delete this campaign and its queue?')">
        <input type="hidden" name="action" value="delete"><button class="btn danger">🗑 Delete</button>
      </form>
    </div>

    <?php if ($c['status'] === 'scheduled'): ?>
      <div class="note">⏰ Scheduled to start at <strong><?= h($c['scheduled_at']) ?></strong>.
        <form method="post" style="display:inline;margin-left:8px">
          <input type="hidden" name="action" value="unschedule"><button class="btn ghost small">Cancel schedule</button>
        </form>
      </div>
    <?php elseif ($c['status'] !== 'running' && $c['status'] !== 'done'): ?>
      <form method="post" class="row" style="margin-top:10px; align-items:flex-end">
        <input type="hidden" name="action" value="schedule">
        <label style="flex:1; margin:0">Schedule start
          <input type="datetime-local" name="scheduled_at" required>
        </label>
        <button class="btn small">⏰ Schedule</button>
      </form>
    <?php endif; ?>
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
    <?php
      $rem = (int)$p['pending'];
      if ($rem > 0) {
        $avg = max(3, ((int)$c['min_delay'] + (int)$c['max_delay']) / 2);
        $cap = $c['account_type'] === 'automation' ? min(((int)$c['daily_limit'] ?: 50), 50) : (int)$c['daily_limit'];
        if ($cap > 0 && $rem > $cap) {
          $days = ceil($rem / $cap);
          $est = "~{$days} day" . ($days > 1 ? 's' : '') . " (capped at {$cap}/day)";
        } else {
          $secs = $rem * $avg;
          $est = $secs < 3600 ? '~' . ceil($secs / 60) . ' min' : '~' . round($secs / 3600, 1) . ' hours';
        }
        echo '<p class="muted small">⏱ Estimated time to finish remaining: <strong>' . h($est) . '</strong></p>';
      }
    ?>
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
