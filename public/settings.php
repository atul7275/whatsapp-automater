<?php
require __DIR__ . '/inc.php';

$msg = null;
if (($_POST['action'] ?? '') === 'save') {
    api_post_json('/api/settings', [
        'openai_api_key'  => $_POST['openai_api_key'] ?? '',
        'openai_model'    => $_POST['openai_model'] ?? 'gpt-4o-mini',
        'optout_keywords' => $_POST['optout_keywords'] ?? '',
        'optout_reply'    => $_POST['optout_reply'] ?? '',
    ]);
    $msg = 'Settings saved.';
}

$s = api_get('/api/settings');
layout_head('Settings');
?>
<h1>Settings</h1>
<?php if ($msg) flash($msg); ?>

<form method="post" class="form">
  <input type="hidden" name="action" value="save">

  <div class="card">
    <h2>🤖 AI message assist (OpenAI)</h2>
    <p class="muted">Optional. With a key set, the composer can generate natural
      wording variations so no two recipients get identical text.</p>
    <label>OpenAI API key
      <input type="text" name="openai_api_key" placeholder="<?= !empty($s['openai_set']) ? '•••••• (saved — leave blank to keep)' : 'sk-...' ?>">
    </label>
    <label>Model <input type="text" name="openai_model" value="<?= h($s['openai_model'] ?? 'gpt-4o-mini') ?>"></label>
  </div>

  <div class="card">
    <h2>🚫 Opt-out / STOP handling</h2>
    <p class="muted">On <strong>automation</strong> accounts, incoming replies are
      scanned for these keywords; a match unsubscribes that number automatically
      and it's skipped by all future campaigns. (Cloud API opt-outs need a Meta
      webhook and are managed in your Meta dashboard.)</p>
    <label>Opt-out keywords (comma-separated, whole-word match)
      <input type="text" name="optout_keywords" value="<?= h($s['optout_keywords'] ?? '') ?>" placeholder="stop, unsubscribe, cancel">
    </label>
    <label>Auto-reply when someone opts out (leave blank to send nothing)
      <textarea name="optout_reply" rows="3"><?= h($s['optout_reply'] ?? '') ?></textarea>
    </label>
    <p class="muted small">People can re-subscribe by replying <code>START</code>
      (also: unstop, subscribe, resume).</p>
  </div>

  <button class="btn">Save settings</button>
</form>
<?php layout_foot(); ?>
