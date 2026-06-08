<?php
require __DIR__ . '/inc.php';

$msg = null;
if (($_POST['action'] ?? '') === 'save') {
    api_post_json('/api/settings', [
        'openai_api_key' => $_POST['openai_api_key'] ?? '',
        'openai_model'   => $_POST['openai_model'] ?? 'gpt-4o-mini',
    ]);
    $msg = 'Settings saved.';
}

$s = api_get('/api/settings');
layout_head('Settings');
?>
<h1>Settings</h1>
<?php if ($msg) flash($msg); ?>

<div class="card">
  <h2>AI message assist (OpenAI)</h2>
  <p class="muted">Optional. With a key set, the campaign composer can generate
    natural, human-sounding wording variations so no two recipients get identical text.</p>
  <form method="post" class="form">
    <input type="hidden" name="action" value="save">
    <label>OpenAI API key
      <input type="text" name="openai_api_key" placeholder="<?= !empty($s['openai_set']) ? '•••••• (saved — leave blank to keep)' : 'sk-...' ?>">
    </label>
    <label>Model
      <input type="text" name="openai_model" value="<?= h($s['openai_model'] ?? 'gpt-4o-mini') ?>">
    </label>
    <button class="btn">Save</button>
  </form>
</div>
<?php layout_foot(); ?>
