<?php
require __DIR__ . '/inc.php';

$msg = null; $msgType = 'ok';

if (($_POST['action'] ?? '') === 'create') {
    $fields = [
        'account_id'  => $_POST['account_id'] ?? '',
        'name'        => $_POST['name'] ?? '',
        'variants'    => $_POST['variants'] ?? '[]',
        'min_delay'   => $_POST['min_delay'] ?? 20,
        'max_delay'   => $_POST['max_delay'] ?? 60,
        'daily_limit' => $_POST['daily_limit'] ?? 50,
        'batch_size'  => $_POST['batch_size'] ?? 15,
        'batch_pause' => $_POST['batch_pause'] ?? 15,
        'active_from' => $_POST['active_from'] ?? '',
        'active_to'   => $_POST['active_to'] ?? '',
        'cloud_template' => $_POST['cloud_template'] ?? '',
        'list_id'     => $_POST['list_id'] ?? '',
    ];
    foreach (['human_typing','natural_timing','micro_breaks'] as $k) {
        if (!empty($_POST[$k])) $fields[$k] = '1';
    }
    $files = !empty($_FILES['media']['name']) ? ['media' => $_FILES['media']] : [];
    $r = api_post('/api/campaigns', $fields, $files);
    if (isset($r['error']) || isset($r['__error'])) { $msg = 'Could not create: ' . ($r['error'] ?? $r['__error']); $msgType = 'err'; }
    else { header('Location: campaign.php?id=' . $r['id']); exit; }
}

$accounts = api_get('/api/accounts');
$contacts = api_get('/api/contacts');
$list = api_get('/api/campaigns');
$ai = api_get('/api/settings');
$lists = api_get('/api/lists');
$accs = $accounts['rows'] ?? [];
layout_head('Campaigns');
?>
<h1>Campaigns</h1>
<?php if ($msg) flash($msg, $msgType); ?>

<?php if (!$accs): ?>
  <div class="note err">Add a WhatsApp account first. <a href="accounts.php">Go to Accounts →</a></div>
<?php elseif (empty($contacts['total'])): ?>
  <div class="note err">No contacts yet. <a href="contacts.php">Import some →</a></div>
<?php endif; ?>

<div class="card">
  <h2>New campaign</h2>
  <form method="post" enctype="multipart/form-data" class="form" id="campForm">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="variants" id="variantsField">

    <div class="row">
      <label style="flex:2">Campaign name <input type="text" name="name" required placeholder="June promo"></label>
      <label style="flex:1">Send from account
        <select name="account_id" id="accountSel" required>
          <?php foreach ($accs as $a): ?>
            <option value="<?= (int)$a['id'] ?>" data-type="<?= h($a['type']) ?>">
              <?= h($a['name']) ?> (<?= $a['type']==='cloud_api'?'API':'auto' ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <div class="row">
      <label style="flex:1">Audience
        <select name="list_id" id="listSel">
          <option value="">★ All contacts</option>
          <?php foreach (($lists['rows'] ?? []) as $l): ?>
            <option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?> (<?= (int)$l['count'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <div style="flex:1; align-self:flex-end" class="muted small" id="audienceNote">
        Unsubscribed numbers &amp; opt-outs are skipped automatically.
      </div>
    </div>

    <label>Message variants <span class="muted small">— each recipient gets a random one, then personalized</span></label>
    <div id="variants"></div>
    <div class="btnrow" style="margin:8px 0">
      <button type="button" class="btn ghost small" onclick="addVariant()">＋ Add variant</button>
      <button type="button" class="btn ghost small" data-ins="{{name}}">{{name}}</button>
      <button type="button" class="btn ghost small" data-ins="{{company}}">{{company}}</button>
      <button type="button" class="btn ghost small" data-ins="{Hi|Hello|Hey}">spintax</button>
      <button type="button" class="btn ghost small" onclick="previewMsg()">👁 Preview</button>
    </div>

    <fieldset>
      <legend>🤖 AI assist <?= empty($ai['openai_set']) ? '(add an OpenAI key in Settings to enable)' : '' ?></legend>
      <div class="row">
        <label style="flex:2">Idea / draft to expand
          <input type="text" id="aiDraft" placeholder="Hi {{name}}, our June sale is on!">
        </label>
        <label>Tone
          <select id="aiTone"><option>friendly</option><option>professional</option><option>casual</option><option>enthusiastic</option></select>
        </label>
        <label style="max-width:90px">Count <input type="number" id="aiCount" value="5" min="1" max="10"></label>
      </div>
      <button type="button" class="btn small" id="aiBtn" onclick="aiGen()" <?= empty($ai['openai_set']) ? 'disabled' : '' ?>>Generate variations</button>
      <span id="aiStatus" class="muted small"></span>
    </fieldset>

    <label>Attachment (optional — image / PDF / doc) <input type="file" name="media"></label>

    <div id="cloudTplWrap" style="display:none">
      <label>Cloud API template name (required outside the 24h window)
        <input type="text" name="cloud_template" placeholder="hello_world">
      </label>
    </div>

    <fieldset>
      <legend>Human-like behavior</legend>
      <div class="checks">
        <label class="chk"><input type="checkbox" name="human_typing" checked> Typing simulation + presence/seen</label>
        <label class="chk"><input type="checkbox" name="natural_timing" checked> Natural non-uniform timing</label>
        <label class="chk"><input type="checkbox" name="micro_breaks" checked> Random micro-breaks</label>
      </div>
      <div class="row">
        <label>Active from
          <select name="active_from"><?php for($i=0;$i<24;$i++) echo '<option value="'.$i.'"'.($i==9?' selected':'').'>'.sprintf('%02d:00',$i).'</option>'; ?><option value="">always</option></select>
        </label>
        <label>Active to
          <select name="active_to"><?php for($i=0;$i<24;$i++) echo '<option value="'.$i.'"'.($i==21?' selected':'').'>'.sprintf('%02d:00',$i).'</option>'; ?><option value="">always</option></select>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Throttling (anti-ban)</legend>
      <div class="row">
        <label>Min delay (sec)<input type="number" name="min_delay" value="20" min="3"></label>
        <label>Max delay (sec)<input type="number" name="max_delay" value="60" min="3"></label>
        <label>Daily limit<input type="number" name="daily_limit" id="dailyLimit" value="50" min="0"></label>
        <label>Batch size<input type="number" name="batch_size" value="15" min="0"></label>
        <label>Batch pause (min)<input type="number" name="batch_pause" value="15" min="0"></label>
      </div>
      <p class="muted small" id="capNote">Automation is hard-capped at 50/day per number.</p>
    </fieldset>

    <button class="btn" <?= (!$accs || empty($contacts['total'])) ? 'disabled' : '' ?>>
      Create &amp; queue campaign
    </button>
  </form>
</div>

<div class="card">
  <h2>🧪 Send a test first</h2>
  <p class="muted small">Sends the first variant (with sample data) to one number, right now, via the selected account.</p>
  <div class="row">
    <label style="flex:1">Test number (with country code)<input type="text" id="testPhone" placeholder="14155550123"></label>
    <div style="align-self:flex-end"><button type="button" class="btn small" onclick="sendTest()">Send test</button></div>
  </div>
  <span id="testStatus" class="muted small"></span>
</div>

<div id="previewBox"></div>

<div class="card">
  <h2>Your campaigns</h2>
  <?php if (empty($list['rows'])): ?><p class="muted">None yet.</p><?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Account</th><th>Status</th><th>Progress</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list['rows'] as $c): $p=$c['progress']; $done=(int)$p['sent']+(int)$p['failed']+(int)$p['invalid']; ?>
        <tr>
          <td><?= (int)$c['id'] ?></td>
          <td><?= h($c['name']) ?></td>
          <td class="small"><?= h($c['account_name']) ?></td>
          <td><span class="pill <?= h($c['status']) ?>"><?= h($c['status']) ?></span></td>
          <td class="muted small"><?= $done ?>/<?= (int)$p['total'] ?> (✓<?= (int)$p['sent'] ?> ✗<?= (int)$p['failed'] ?>)</td>
          <td><a class="btn small" href="campaign.php?id=<?= (int)$c['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<script src="assets/composer.js"></script>
<?php layout_foot(); ?>
