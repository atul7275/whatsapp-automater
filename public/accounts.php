<?php
require __DIR__ . '/inc.php';

$msg = null; $msgType = 'ok';
$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $r = api_post_json('/api/accounts', [
        'name' => $_POST['name'] ?? '',
        'type' => $_POST['type'] ?? 'automation',
        'cloud_phone_id' => $_POST['cloud_phone_id'] ?? '',
        'cloud_token' => $_POST['cloud_token'] ?? '',
        'cloud_lang' => $_POST['cloud_lang'] ?? 'en_US',
        'daily_cap' => $_POST['daily_cap'] ?? 0,
    ]);
    if (isset($r['error']) || isset($r['__error'])) { $msg = 'Could not add: ' . ($r['error'] ?? $r['__error']); $msgType = 'err'; }
    else { header('Location: accounts.php'); exit; }
} elseif ($action === 'cap') {
    api_post_json('/api/accounts/' . (int)$_POST['id'] . '/cap', ['daily_cap' => $_POST['daily_cap'] ?? 0]);
    header('Location: accounts.php'); exit;
} elseif ($action === 'delete') {
    api_post_json('/api/accounts/' . (int)$_POST['id'] . '/delete');
    header('Location: accounts.php'); exit;
} elseif ($action === 'logout') {
    api_post_json('/api/accounts/' . (int)$_POST['id'] . '/logout');
    header('Location: accounts.php'); exit;
}

$data = api_get('/api/accounts');
layout_head('Accounts');
?>
<h1>WhatsApp accounts</h1>
<?php if ($msg) flash($msg, $msgType); ?>

<?php foreach (($data['rows'] ?? []) as $a): ?>
  <div class="card">
    <div class="acc-head">
      <div>
        <h2 style="margin:0"><?= h($a['name']) ?>
          <span class="pill <?= h($a['state']) ?>"><?= h($a['state']) ?></span></h2>
        <p class="muted small">
          <?= $a['type'] === 'cloud_api' ? 'Business Cloud API' : 'Humanized automation' ?>
          <?php if (!empty($a['info']['number'])): ?> · <?= h($a['info']['number']) ?><?php endif; ?>
          <?php if (!empty($a['info']['pushname'])): ?> · <?= h($a['info']['pushname']) ?><?php endif; ?>
          <?php
            $cap = (int)($a['daily_cap'] ?? 0);
            $shown = $a['type'] === 'automation' ? ($cap > 0 ? min($cap, 50) : 50) : ($cap > 0 ? $cap : '∞');
          ?> · <?= (int)$a['sent_today'] ?>/<?= $shown ?> sent today
        </p>
        <?php if (!empty($a['info']['error'])): ?><p class="err small"><?= h($a['info']['error']) ?></p><?php endif; ?>
        <form method="post" class="row" style="margin-top:6px;max-width:320px">
          <input type="hidden" name="action" value="cap"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <label style="flex:1;margin:0;font-weight:500">Daily cap (0 = <?= $a['type']==='automation'?'max 50':'unlimited' ?>)
            <input type="number" name="daily_cap" value="<?= $cap ?>" min="0" <?= $a['type']==='automation'?'max="50"':'' ?>>
          </label>
          <div style="align-self:flex-end"><button class="btn ghost small">Save</button></div>
        </form>
      </div>
      <div class="btnrow">
        <?php if ($a['type'] === 'automation'): ?>
        <form method="post" onsubmit="return confirm('Log out this number?')">
          <input type="hidden" name="action" value="logout"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="btn ghost small">Log out</button>
        </form>
        <?php endif; ?>
        <form method="post" onsubmit="return confirm('Delete this account and its campaigns?')">
          <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
          <button class="btn danger small">Delete</button>
        </form>
      </div>
    </div>
    <?php if (($a['state'] ?? '') === 'qr' && !empty($a['qr'])): ?>
      <p>Scan with this number: WhatsApp → <em>Settings → Linked Devices → Link a Device</em></p>
      <img class="qr" src="<?= h($a['qr']) ?>" alt="QR">
    <?php elseif (in_array($a['state'] ?? '', ['starting','authenticated'])): ?>
      <p class="muted">Connecting… QR will appear shortly.</p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="card">
  <h2>Add an account</h2>
  <form method="post" class="form" id="addForm">
    <input type="hidden" name="action" value="add">
    <label>Account name <input type="text" name="name" required placeholder="My business line"></label>
    <label>Daily cap (0 = automation max 50 / API unlimited)
      <input type="number" name="daily_cap" value="0" min="0">
    </label>
    <label>Type
      <select name="type" id="acctype" onchange="document.getElementById('cloud').style.display=this.value==='cloud_api'?'block':'none'">
        <option value="automation">Humanized automation (scan QR — personal/business number)</option>
        <option value="cloud_api">WhatsApp Business Cloud API (official, no QR)</option>
      </select>
    </label>
    <div id="cloud" style="display:none">
      <div class="note">From your Meta developer dashboard → WhatsApp → API setup.</div>
      <label>Phone number ID <input type="text" name="cloud_phone_id" placeholder="e.g. 123456789012345"></label>
      <label>Access token <input type="text" name="cloud_token" placeholder="EAAG..."></label>
      <label>Default template language <input type="text" name="cloud_lang" value="en_US"></label>
    </div>
    <button class="btn">Add account</button>
  </form>
</div>

<?php if (array_filter($data['rows'] ?? [], fn($a) => in_array($a['state'], ['qr','starting','authenticated']))): ?>
<script>setTimeout(() => location.reload(), 4000);</script>
<?php endif; ?>
<?php layout_foot(); ?>
