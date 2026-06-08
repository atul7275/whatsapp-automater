<?php
require __DIR__ . '/inc.php';

$msg = null; $msgType = 'ok';
$action = $_POST['action'] ?? '';

if ($action === 'import' && !empty($_FILES['file']['name'])) {
    $r = api_post('/api/contacts/import', ['list' => $_POST['list'] ?? ''], ['file' => $_FILES['file']]);
    if (isset($r['__error']) || isset($r['error'])) {
        $msg = 'Import failed: ' . ($r['error'] ?? $r['__error']); $msgType = 'err';
    } else {
        $msg = "Imported {$r['imported']} contacts (skipped {$r['skipped']})"
             . ($r['list'] ? " into list “{$r['list']}”" : "") . ". Phone column: “{$r['phoneColumn']}”.";
    }
} elseif ($action === 'clear') {
    api_post('/api/contacts', [], [], 'DELETE');
    $msg = 'All contacts removed.';
} elseif ($action === 'create_list') {
    api_post_json('/api/lists', ['name' => $_POST['name'] ?? '']);
    $msg = 'List created.';
} elseif ($action === 'delete_list') {
    api_post_json('/api/lists/' . (int)$_POST['id'] . '/delete');
    $msg = 'List deleted (contacts kept).';
} elseif ($action === 'optout_add') {
    api_post_json('/api/optouts', ['phone' => $_POST['phone'] ?? '']);
    $msg = 'Number added to opt-out list.';
} elseif ($action === 'optout_remove') {
    api_post_json('/api/optouts/delete', ['phone' => $_POST['phone'] ?? '']);
    $msg = 'Number re-subscribed.';
}

$filter = isset($_GET['list_id']) ? '?list_id=' . (int)$_GET['list_id'] : '';
$data    = api_get('/api/contacts' . $filter);
$lists   = api_get('/api/lists');
$optouts = api_get('/api/optouts');
layout_head('Contacts');
?>
<h1>Contacts</h1>
<?php if ($msg) flash($msg, $msgType); ?>

<div class="grid">
  <div class="card">
    <h2>Import from CSV / Excel</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="import">
      <label>File <input type="file" name="file" accept=".csv,.xlsx,.xls" required></label>
      <label>Add to list (optional — type a new name or pick existing)
        <input type="text" name="list" list="listnames" placeholder="e.g. VIP customers">
      </label>
      <datalist id="listnames">
        <?php foreach (($lists['rows'] ?? []) as $l): ?><option value="<?= h($l['name']) ?>"><?php endforeach; ?>
      </datalist>
      <button class="btn">Upload &amp; import</button>
    </form>
    <div class="note">
      First row = headers. A <code>phone</code>/<code>mobile</code>/<code>number</code>
      column is the number (<strong>include country code</strong>, e.g.
      <code>14155550123</code>). <code>name</code> feeds <code>{{name}}</code>;
      other columns become placeholders like <code>{{company}}</code>.
    </div>
  </div>

  <div class="card">
    <h2>Lists</h2>
    <p><a href="contacts.php"<?= $filter==='' ? ' style="font-weight:700"' : '' ?>>★ All contacts</a>
       <span class="muted small">(campaigns can target a list or everyone)</span></p>
    <table>
      <tbody>
      <?php foreach (($lists['rows'] ?? []) as $l): ?>
        <tr>
          <td><a href="contacts.php?list_id=<?= (int)$l['id'] ?>"><?= h($l['name']) ?></a></td>
          <td class="muted small"><?= (int)$l['count'] ?> contacts</td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this list? Contacts are kept.')" style="margin:0">
              <input type="hidden" name="action" value="delete_list"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <button class="btn ghost small">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post" class="row" style="margin-top:8px">
      <input type="hidden" name="action" value="create_list">
      <input type="text" name="name" placeholder="New list name" required style="flex:1">
      <button class="btn small">Create</button>
    </form>
  </div>
</div>

<div class="card">
  <h2>🚫 Opt-outs <span class="muted small">(<?= (int)($optouts['total'] ?? 0) ?>)</span></h2>
  <p class="muted small">Numbers that replied STOP (auto-detected on automation accounts) or were added manually. They are skipped by every campaign.</p>
  <form method="post" class="row" style="margin-bottom:10px">
    <input type="hidden" name="action" value="optout_add">
    <input type="text" name="phone" placeholder="Add number to opt-out (digits, with country code)" required style="flex:1">
    <button class="btn small danger">Add opt-out</button>
  </form>
  <?php if (!empty($optouts['rows'])): ?>
    <table>
      <thead><tr><th>Phone</th><th>Reason</th><th>When</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($optouts['rows'] as $o): ?>
        <tr>
          <td><?= h($o['phone']) ?></td>
          <td class="muted small"><?= h($o['keyword']) ?></td>
          <td class="muted small"><?= h($o['created_at']) ?></td>
          <td>
            <form method="post" style="margin:0">
              <input type="hidden" name="action" value="optout_remove"><input type="hidden" name="phone" value="<?= h($o['phone']) ?>">
              <button class="btn ghost small">Re-subscribe</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <div class="acc-head">
    <h2 style="margin:0">
      <?= $filter ? 'List contacts' : 'All contacts' ?>: <strong><?= (int)($data['total'] ?? 0) ?></strong>
      <?php if (!empty($data['unsubscribed'])): ?><span class="pill invalid"><?= (int)$data['unsubscribed'] ?> unsubscribed</span><?php endif; ?>
    </h2>
    <?php if (!empty($data['total'])): ?>
      <form method="post" onsubmit="return confirm('Delete ALL contacts?')" style="margin:0">
        <input type="hidden" name="action" value="clear">
        <button class="btn danger small">Clear all</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!empty($data['rows'])): ?>
    <table>
      <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Status</th><th>Fields</th></tr></thead>
      <tbody>
      <?php foreach ($data['rows'] as $c): ?>
        <tr>
          <td><?= (int)$c['id'] ?></td>
          <td><?= h($c['name']) ?></td>
          <td><?= h($c['phone']) ?></td>
          <td><?= !empty($c['unsubscribed']) ? '<span class="pill invalid">unsubscribed</span>' : '<span class="pill sent">active</span>' ?></td>
          <td class="muted small"><?= h($c['fields'] === '{}' ? '' : $c['fields']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?><p class="muted">No contacts<?= $filter ? ' in this list' : '' ?> yet.</p><?php endif; ?>
</div>
<?php layout_foot(); ?>
