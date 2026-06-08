<?php
require __DIR__ . '/inc.php';

$msg = null;
if (($_POST['action'] ?? '') === 'save') {
    $payload = [
        'openai_api_key'  => $_POST['openai_api_key'] ?? '',
        'openai_model'    => $_POST['openai_model'] ?? 'gpt-4o-mini',
        'optout_keywords' => $_POST['optout_keywords'] ?? '',
        'optout_reply'    => $_POST['optout_reply'] ?? '',
    ];
    foreach (['def_min_delay','def_max_delay','def_daily_limit','def_batch_size','def_batch_pause','def_active_from','def_active_to'] as $k)
        $payload[$k] = $_POST[$k] ?? '';
    foreach (['def_human_typing','def_natural_timing','def_micro_breaks'] as $k)
        $payload[$k] = !empty($_POST[$k]) ? '1' : '0';
    if (!empty($_POST['panel_password'])) $payload['panel_password'] = $_POST['panel_password'];
    if (!empty($_POST['panel_password_clear'])) $payload['panel_password_clear'] = true;
    api_post_json('/api/settings', $payload);
    $msg = 'Settings saved.';
}

$s = api_get('/api/settings');
$def = fn($k, $d = '') => h($s[$k] ?? $d);
layout_head('Settings');
?>
<h1>Settings</h1>
<?php if ($msg) flash($msg); ?>

<form method="post" class="form">
  <input type="hidden" name="action" value="save">

  <div class="card">
    <h2>AI message assist (OpenAI)</h2>
    <p class="muted">Optional. Generates natural wording variations in the composer.</p>
    <label>OpenAI API key
      <input type="text" name="openai_api_key" placeholder="<?= !empty($s['openai_set']) ? '•••••• (saved — leave blank to keep)' : 'sk-...' ?>">
    </label>
    <label>Model <input type="text" name="openai_model" value="<?= $def('openai_model','gpt-4o-mini') ?>"></label>
  </div>

  <div class="card">
    <h2>Default campaign settings</h2>
    <p class="muted">Pre-fills the campaign composer for new campaigns.</p>
    <div class="row">
      <label>Min delay (s)<input type="number" name="def_min_delay" value="<?= $def('def_min_delay','20') ?>" min="3"></label>
      <label>Max delay (s)<input type="number" name="def_max_delay" value="<?= $def('def_max_delay','60') ?>" min="3"></label>
      <label>Daily limit<input type="number" name="def_daily_limit" value="<?= $def('def_daily_limit','50') ?>" min="0"></label>
      <label>Batch size<input type="number" name="def_batch_size" value="<?= $def('def_batch_size','15') ?>" min="0"></label>
      <label>Batch pause (m)<input type="number" name="def_batch_pause" value="<?= $def('def_batch_pause','15') ?>" min="0"></label>
    </div>
    <div class="row">
      <label>Active from (hour)<input type="number" name="def_active_from" value="<?= $def('def_active_from','9') ?>" min="0" max="23"></label>
      <label>Active to (hour)<input type="number" name="def_active_to" value="<?= $def('def_active_to','21') ?>" min="0" max="23"></label>
    </div>
    <div class="checks" style="margin-top:12px">
      <label class="chk"><input type="checkbox" name="def_human_typing" <?= ($s['def_human_typing'] ?? '1')==='1'?'checked':'' ?>> Typing simulation</label>
      <label class="chk"><input type="checkbox" name="def_natural_timing" <?= ($s['def_natural_timing'] ?? '1')==='1'?'checked':'' ?>> Natural timing</label>
      <label class="chk"><input type="checkbox" name="def_micro_breaks" <?= ($s['def_micro_breaks'] ?? '1')==='1'?'checked':'' ?>> Micro-breaks</label>
    </div>
  </div>

  <div class="card">
    <h2>Opt-out / STOP handling</h2>
    <p class="muted">Automation accounts auto-unsubscribe replies matching these keywords.</p>
    <label>Opt-out keywords (comma-separated)
      <input type="text" name="optout_keywords" value="<?= $def('optout_keywords') ?>" placeholder="stop, unsubscribe, cancel">
    </label>
    <label>Auto-reply on opt-out (blank = none)
      <textarea name="optout_reply" rows="3"><?= $def('optout_reply') ?></textarea>
    </label>
  </div>

  <div class="card">
    <h2>Panel password</h2>
    <p class="muted">Optional. Require a password to open this control panel.
      <?= !empty($s['panel_password_set']) ? '<strong class="ok">Currently enabled.</strong>' : 'Currently disabled.' ?></p>
    <label>Set / change password (blank = keep current)
      <input type="text" name="panel_password" placeholder="new password">
    </label>
    <?php if (!empty($s['panel_password_set'])): ?>
      <label class="chk" style="margin-top:8px"><input type="checkbox" name="panel_password_clear"> Remove password (disable lock)</label>
    <?php endif; ?>
  </div>

  <button class="btn">Save settings</button>
</form>
<?php layout_foot(); ?>
