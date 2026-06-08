<?php
require __DIR__ . '/inc.php';

$id = (int)($_GET['id'] ?? 0);

if (($_POST['action'] ?? '') === 'save') {
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
    foreach (['human_typing','natural_timing','micro_breaks'] as $k) $fields[$k] = !empty($_POST[$k]) ? '1' : '';
    if (!empty($_POST['remove_media'])) $fields['remove_media'] = '1';
    $files = !empty($_FILES['media']['name']) ? ['media' => $_FILES['media']] : [];
    $r = api_post("/api/campaigns/$id/edit", $fields, $files);
    if (isset($r['error']) || isset($r['__error'])) { $err = 'Could not save: ' . ($r['error'] ?? $r['__error']); }
    else { header("Location: campaign.php?id=$id"); exit; }
}

$c = api_get("/api/campaigns/$id");
$accounts = api_get('/api/accounts');
$lists = api_get('/api/lists');
$ai = api_get('/api/settings');
$accs = $accounts['rows'] ?? [];
layout_head('Edit campaign');

if (isset($c['__error']) || isset($c['error'])) { flash('Not found.', 'err'); layout_foot(); exit; }
if ($c['status'] === 'running') { flash('Pause the campaign before editing it.', 'err'); echo '<p><a href="campaign.php?id='.$id.'">← Back</a></p>'; layout_foot(); exit; }

$variants = json_decode($c['variants'] ?? '[]', true) ?: [];
$af = (int)$c['active_from']; $at = (int)$c['active_to'];
$ck = fn($k) => ((int)$c[$k] === 1) ? 'checked' : '';
?>
<h1>Edit campaign</h1>
<?php if (!empty($err)) flash($err, 'err'); ?>
<p><a href="campaign.php?id=<?= $id ?>">← Back to campaign</a></p>

<div class="card">
  <form method="post" enctype="multipart/form-data" class="form" id="campForm">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="variants" id="variantsField">

    <div class="row">
      <label style="flex:2">Campaign name <input type="text" name="name" required value="<?= h($c['name']) ?>"></label>
      <label style="flex:1">Send from account
        <select name="account_id" id="accountSel" required>
          <?php foreach ($accs as $a): ?>
            <option value="<?= (int)$a['id'] ?>" data-type="<?= h($a['type']) ?>" <?= $a['id']==$c['account_id']?'selected':'' ?>>
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
            <option value="<?= (int)$l['id'] ?>" <?= (int)$c['list_id']===(int)$l['id']?'selected':'' ?>><?= h($l['name']) ?> (<?= (int)$l['count'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <div style="flex:1; align-self:flex-end" class="muted small" id="audienceNote"></div>
    </div>

    <label>Message variants <span class="muted small">— each recipient gets a random one</span></label>
    <div id="variants"></div>
    <div class="btnrow" style="margin:8px 0">
      <button type="button" class="btn ghost small" onclick="addVariant()">＋ Add variant</button>
      <button type="button" class="btn ghost small" data-ins="{{name}}">{{name}}</button>
      <button type="button" class="btn ghost small" data-ins="{{company}}">{{company}}</button>
      <button type="button" class="btn ghost small" data-ins="{Hi|Hello|Hey}">spintax</button>
      <button type="button" class="btn ghost small" onclick="previewMsg()">👁 Preview</button>
    </div>

    <fieldset>
      <legend>🤖 AI assist <?= empty($ai['openai_set']) ? '(add an OpenAI key in Settings)' : '' ?></legend>
      <div class="row">
        <label style="flex:2">Idea / draft<input type="text" id="aiDraft" placeholder="Hi {{name}}, ..."></label>
        <label>Tone<select id="aiTone"><option>friendly</option><option>professional</option><option>casual</option><option>enthusiastic</option></select></label>
        <label style="max-width:90px">Count<input type="number" id="aiCount" value="5" min="1" max="10"></label>
      </div>
      <button type="button" class="btn small" id="aiBtn" onclick="aiGen()" <?= empty($ai['openai_set'])?'disabled':'' ?>>Generate variations</button>
      <span id="aiStatus" class="muted small"></span>
    </fieldset>

    <label>Attachment <?php if ($c['media_name']): ?><span class="muted small">— current: 📎 <?= h($c['media_name']) ?></span><?php endif; ?>
      <input type="file" name="media"></label>
    <?php if ($c['media_name']): ?><label class="chk"><input type="checkbox" name="remove_media" value="1"> Remove current attachment</label><?php endif; ?>

    <div id="cloudTplWrap" style="display:none">
      <label>Cloud API template name <input type="text" name="cloud_template" value="<?= h($c['cloud_template'] ?? '') ?>"></label>
    </div>

    <fieldset>
      <legend>Human-like behavior</legend>
      <div class="checks">
        <label class="chk"><input type="checkbox" name="human_typing" <?= $ck('human_typing') ?>> Typing simulation</label>
        <label class="chk"><input type="checkbox" name="natural_timing" <?= $ck('natural_timing') ?>> Natural timing</label>
        <label class="chk"><input type="checkbox" name="micro_breaks" <?= $ck('micro_breaks') ?>> Micro-breaks</label>
      </div>
      <div class="row">
        <label>Active from<select name="active_from"><?php for($i=0;$i<24;$i++) echo '<option value="'.$i.'"'.($i===$af?' selected':'').'>'.sprintf('%02d:00',$i).'</option>'; ?><option value="" <?= $af<0?'selected':'' ?>>always</option></select></label>
        <label>Active to<select name="active_to"><?php for($i=0;$i<24;$i++) echo '<option value="'.$i.'"'.($i===$at?' selected':'').'>'.sprintf('%02d:00',$i).'</option>'; ?><option value="" <?= $at<0?'selected':'' ?>>always</option></select></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Throttling (anti-ban)</legend>
      <div class="row">
        <label>Min delay (sec)<input type="number" name="min_delay" value="<?= (int)$c['min_delay'] ?>" min="3"></label>
        <label>Max delay (sec)<input type="number" name="max_delay" value="<?= (int)$c['max_delay'] ?>" min="3"></label>
        <label>Daily limit<input type="number" name="daily_limit" id="dailyLimit" value="<?= (int)$c['daily_limit'] ?>" min="0"></label>
        <label>Batch size<input type="number" name="batch_size" value="<?= (int)$c['batch_size'] ?>" min="0"></label>
        <label>Batch pause (min)<input type="number" name="batch_pause" value="<?= (int)$c['batch_pause'] ?>" min="0"></label>
      </div>
      <p class="muted small" id="capNote"></p>
    </fieldset>

    <button class="btn">Save changes</button>
    <a class="btn ghost" href="campaign.php?id=<?= $id ?>">Cancel</a>
  </form>
</div>

<div class="card">
  <h2>🧪 Send a test</h2>
  <div class="row">
    <label style="flex:1">Test number<input type="text" id="testPhone" placeholder="14155550123"></label>
    <div style="align-self:flex-end"><button type="button" class="btn small" onclick="sendTest()">Send test</button></div>
  </div>
  <span id="testStatus" class="muted small"></span>
</div>
<div id="previewBox"></div>

<script>window.EDIT_MODE = true; window.EDIT_VARIANTS = <?= json_encode(array_values($variants)) ?>;</script>
<script src="assets/composer.js"></script>
<?php layout_foot(); ?>
