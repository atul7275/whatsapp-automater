<?php
require __DIR__ . '/inc.php';

$msg = null; $msgType = 'ok';
$action = $_POST['action'] ?? '';

if ($action === 'import' && !empty($_FILES['file']['name'])) {
    $r = api_post('/api/contacts/import', [], ['file' => $_FILES['file']]);
    if (isset($r['__error']) || isset($r['error'])) {
        $msg = 'Import failed: ' . ($r['error'] ?? $r['__error']); $msgType = 'err';
    } else {
        $msg = "Imported {$r['imported']} contacts (skipped {$r['skipped']}). "
             . "Phone column: “{$r['phoneColumn']}”" . ($r['nameColumn'] ? ", name column: “{$r['nameColumn']}”" : "");
    }
} elseif ($action === 'clear') {
    api_post('/api/contacts', [], [], 'DELETE');
    $msg = 'All contacts removed.';
}

$data = api_get('/api/contacts');
layout_head('Contacts');
?>
<h1>Contacts</h1>
<?php if ($msg) flash($msg, $msgType); ?>

<div class="grid">
  <div class="card">
    <h2>Import from CSV / Excel</h2>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="import">
      <input type="file" name="file" accept=".csv,.xlsx,.xls" required>
      <button class="btn">Upload &amp; import</button>
    </form>
    <div class="note">
      First row must be headers. A column named <code>phone</code> /
      <code>mobile</code> / <code>number</code> is used for the number
      (include the country code, e.g. <code>14155550123</code>). A
      <code>name</code> column is used for <code>{{name}}</code>. Any other
      columns become placeholders, e.g. <code>{{company}}</code>.
    </div>
  </div>

  <div class="card">
    <h2>Stored contacts</h2>
    <p>Total: <strong><?= (int)($data['total'] ?? 0) ?></strong></p>
    <?php if (!empty($data['total'])): ?>
      <form method="post" onsubmit="return confirm('Delete ALL contacts?')">
        <input type="hidden" name="action" value="clear">
        <button class="btn danger">Clear all</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($data['rows'])): ?>
<div class="card">
  <h2>Preview (latest 200)</h2>
  <table>
    <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Extra fields</th></tr></thead>
    <tbody>
    <?php foreach ($data['rows'] as $c): ?>
      <tr>
        <td><?= (int)$c['id'] ?></td>
        <td><?= h($c['name']) ?></td>
        <td><?= h($c['phone']) ?></td>
        <td class="muted small"><?= h($c['fields'] === '{}' ? '' : $c['fields']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php layout_foot(); ?>
