<?php
require __DIR__ . '/inc.php';

$msg = null; $msgType = 'ok';
$action = $_POST['action'] ?? '';

if ($action === 'import' && !empty($_FILES['file']['name'])) {
    $r = api_post('/api/contacts/import', ['list' => $_POST['list'] ?? ''], ['file' => $_FILES['file']]);
    $msg = (isset($r['error']) || isset($r['__error']))
        ? 'Import failed: ' . ($r['error'] ?? $r['__error'])
        : "Imported {$r['imported']} contacts (skipped {$r['skipped']})" . ($r['list'] ? " into “{$r['list']}”" : "") . ".";
    if (isset($r['error']) || isset($r['__error'])) $msgType = 'err';
} elseif ($action === 'add_contact') {
    $r = api_post_json('/api/contacts', ['name' => $_POST['name'] ?? '', 'phone' => $_POST['phone'] ?? '', 'list' => $_POST['list'] ?? '']);
    $msg = !empty($r['id']) ? 'Contact added.' : 'Could not add: ' . ($r['error'] ?? $r['__error'] ?? '');
    if (empty($r['id'])) $msgType = 'err';
} elseif ($action === 'edit_contact') {
    $r = api_post_json('/api/contacts/' . (int)$_POST['id'], ['name' => $_POST['name'] ?? '', 'phone' => $_POST['phone'] ?? '']);
    $msg = !empty($r['ok']) ? 'Contact updated.' : 'Could not update: ' . ($r['error'] ?? '');
    if (empty($r['ok'])) $msgType = 'err';
    else { header('Location: contacts.php'); exit; }
} elseif ($action === 'delete_contact') {
    api_post('/api/contacts/' . (int)$_POST['id'], [], [], 'DELETE');
    $msg = 'Contact deleted.';
} elseif ($action === 'bulk_delete') {
    $ids = array_map('intval', $_POST['ids'] ?? []);
    if ($ids) { api_post_json('/api/contacts/bulk-delete', ['ids' => $ids]); $msg = count($ids) . ' contact(s) deleted.'; }
} elseif ($action === 'assign') {
    $ids = array_map('intval', $_POST['ids'] ?? []);
    if ($ids && !empty($_POST['list_id'])) {
        api_post_json('/api/contacts/assign', ['ids' => $ids, 'list_id' => (int)$_POST['list_id'], 'action' => $_POST['assign_action'] ?? 'add']);
        $msg = count($ids) . ' contact(s) ' . ($_POST['assign_action'] === 'remove' ? 'removed from' : 'added to') . ' list.';
    } else { $msg = 'Select contacts and a list first.'; $msgType = 'err'; }
} elseif ($action === 'clear') {
    api_post('/api/contacts', [], [], 'DELETE'); $msg = 'All contacts removed.';
} elseif ($action === 'create_list') {
    api_post_json('/api/lists', ['name' => $_POST['name'] ?? '']); $msg = 'List created.';
} elseif ($action === 'delete_list') {
    api_post_json('/api/lists/' . (int)$_POST['id'] . '/delete'); $msg = 'List deleted (contacts kept).';
} elseif ($action === 'optout_add') {
    api_post_json('/api/optouts', ['phone' => $_POST['phone'] ?? '']); $msg = 'Number added to opt-out list.';
} elseif ($action === 'optout_remove') {
    api_post_json('/api/optouts/delete', ['phone' => $_POST['phone'] ?? '']); $msg = 'Number re-subscribed.';
}

$q = trim($_GET['q'] ?? '');
$listFilter = isset($_GET['list_id']) ? (int)$_GET['list_id'] : 0;
$query = '/api/contacts?' . http_build_query(array_filter(['q' => $q, 'list_id' => $listFilter ?: null]));
$data    = api_get($query);
$lists   = api_get('/api/lists');
$optouts = api_get('/api/optouts');
$editing = isset($_GET['edit']) ? api_get('/api/contacts/' . (int)$_GET['edit']) : null;
$listRows = $lists['rows'] ?? [];
layout_head('Contacts');
?>
<h1>Contacts</h1>
<?php if ($msg) flash($msg, $msgType); ?>

<?php if ($editing && empty($editing['error'])): ?>
<div class="card">
  <h2>Edit contact #<?= (int)$editing['id'] ?></h2>
  <form method="post" class="row">
    <input type="hidden" name="action" value="edit_contact"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
    <label style="flex:1">Name<input type="text" name="name" value="<?= h($editing['name']) ?>"></label>
    <label style="flex:1">Phone (country code)<input type="text" name="phone" value="<?= h($editing['phone']) ?>" required></label>
    <div style="align-self:flex-end"><button class="btn small">Save</button> <a class="btn ghost small" href="contacts.php">Cancel</a></div>
  </form>
</div>
<?php endif; ?>

<div class="grid">
  <div class="card">
    <h2>Add a contact</h2>
    <form method="post" class="row">
      <input type="hidden" name="action" value="add_contact">
      <label style="flex:1">Name<input type="text" name="name" placeholder="Jane Doe"></label>
      <label style="flex:1">Phone (country code)<input type="text" name="phone" placeholder="14155550123" required></label>
      <label style="flex:1">List (optional)<input type="text" name="list" list="listnames" placeholder="VIP"></label>
      <div style="align-self:flex-end"><button class="btn small">Add</button></div>
    </form>
    <h2 style="margin-top:18px">Import CSV / Excel</h2>
    <form method="post" enctype="multipart/form-data" class="row">
      <input type="hidden" name="action" value="import">
      <label style="flex:1">File<input type="file" name="file" accept=".csv,.xlsx,.xls" required></label>
      <label style="flex:1">Add to list<input type="text" name="list" list="listnames" placeholder="optional"></label>
      <div style="align-self:flex-end"><button class="btn small">Import</button></div>
    </form>
    <datalist id="listnames"><?php foreach ($listRows as $l): ?><option value="<?= h($l['name']) ?>"><?php endforeach; ?></datalist>
    <p class="note small">Include the country code (e.g. <code>14155550123</code>). Extra spreadsheet columns become <code>{{placeholders}}</code>.</p>
  </div>

  <div class="card">
    <h2>Lists</h2>
    <p><a href="contacts.php"<?= !$listFilter ? ' style="font-weight:700"' : '' ?>>★ All contacts</a></p>
    <table><tbody>
      <?php foreach ($listRows as $l): ?>
        <tr>
          <td><a href="contacts.php?list_id=<?= (int)$l['id'] ?>"<?= $listFilter==(int)$l['id']?' style="font-weight:700"':'' ?>><?= h($l['name']) ?></a></td>
          <td class="muted small"><?= (int)$l['count'] ?></td>
          <td><form method="post" onsubmit="return confirm('Delete this list? Contacts kept.')" style="margin:0"><input type="hidden" name="action" value="delete_list"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn ghost small">✕</button></form></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <form method="post" class="row" style="margin-top:8px">
      <input type="hidden" name="action" value="create_list">
      <input type="text" name="name" placeholder="New list name" required style="flex:1">
      <button class="btn small">Create</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="acc-head">
    <h2 style="margin:0">
      <?= $listFilter ? 'List contacts' : 'All contacts' ?>: <strong><?= (int)($data['total'] ?? 0) ?></strong>
      <?php if (!empty($data['unsubscribed'])): ?><span class="pill invalid"><?= (int)$data['unsubscribed'] ?> unsubscribed</span><?php endif; ?>
    </h2>
    <form method="get" class="row" style="margin:0">
      <?php if ($listFilter): ?><input type="hidden" name="list_id" value="<?= $listFilter ?>"><?php endif; ?>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search name or number" style="width:220px">
      <button class="btn ghost small">Search</button>
      <?php if ($q): ?><a class="btn ghost small" href="contacts.php<?= $listFilter ? '?list_id='.$listFilter : '' ?>">Clear</a><?php endif; ?>
    </form>
  </div>

  <?php if (!empty($data['rows'])): ?>
  <form method="post" id="bulkForm">
    <div class="btnrow" style="margin:10px 0">
      <button class="btn danger small" name="action" value="bulk_delete" onclick="return confirm('Delete selected contacts?')">Delete selected</button>
      <span class="muted small">or</span>
      <select name="list_id" style="width:auto">
        <option value="">choose list…</option>
        <?php foreach ($listRows as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn small" name="action" value="assign">Add to list</button>
      <button class="btn ghost small" name="action" value="assign" onclick="document.getElementById('assignAction').value='remove'">Remove from list</button>
      <input type="hidden" name="assign_action" id="assignAction" value="add">
    </div>
    <table>
      <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.csel').forEach(c=>c.checked=this.checked)"></th><th>Name</th><th>Phone</th><th>Status</th><th>Fields</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($data['rows'] as $c): ?>
        <tr>
          <td><input type="checkbox" class="csel" name="ids[]" value="<?= (int)$c['id'] ?>"></td>
          <td><?= h($c['name']) ?></td>
          <td><?= h($c['phone']) ?></td>
          <td><?= !empty($c['unsubscribed']) ? '<span class="pill invalid">unsub</span>' : '<span class="pill sent">active</span>' ?></td>
          <td class="muted small"><?= h($c['fields'] === '{}' ? '' : $c['fields']) ?></td>
          <td class="btnrow">
            <a class="btn ghost small" href="contacts.php?edit=<?= (int)$c['id'] ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </form>
  <div class="btnrow" style="margin-top:10px">
    <form method="post" onsubmit="return confirm('Delete ALL contacts?')" style="margin:0"><input type="hidden" name="action" value="clear"><button class="btn ghost small">Clear all contacts</button></form>
  </div>
  <?php else: ?><p class="muted">No contacts<?= $q ? ' match your search' : ($listFilter ? ' in this list' : ' yet') ?>.</p><?php endif; ?>
</div>

<div class="card">
  <h2>🚫 Opt-outs <span class="muted small">(<?= (int)($optouts['total'] ?? 0) ?>)</span></h2>
  <form method="post" class="row" style="margin-bottom:10px">
    <input type="hidden" name="action" value="optout_add">
    <input type="text" name="phone" placeholder="Add number to opt-out" required style="flex:1">
    <button class="btn small danger">Add opt-out</button>
  </form>
  <?php if (!empty($optouts['rows'])): ?>
    <table><thead><tr><th>Phone</th><th>Reason</th><th>When</th><th></th></tr></thead><tbody>
      <?php foreach ($optouts['rows'] as $o): ?>
        <tr><td><?= h($o['phone']) ?></td><td class="muted small"><?= h($o['keyword']) ?></td><td class="muted small"><?= h($o['created_at']) ?></td>
          <td><form method="post" style="margin:0"><input type="hidden" name="action" value="optout_remove"><input type="hidden" name="phone" value="<?= h($o['phone']) ?>"><button class="btn ghost small">Re-subscribe</button></form></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>
