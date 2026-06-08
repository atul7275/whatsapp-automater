<?php
// Shared config + helpers for the PHP control panel.
// The PHP side is a thin UI: it talks to the Node engine over HTTP.

define('ENGINE', getenv('ENGINE_URL') ?: 'http://localhost:3000');

/** GET JSON from the engine. Returns decoded array or ['__error'=>msg]. */
function api_get($path) {
    $ch = curl_init(ENGINE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    if ($body === false) return ['__error' => 'Engine not reachable: ' . curl_error($ch)];
    return json_decode($body, true) ?: ['__error' => 'Bad response'];
}

/** POST to the engine. $files = ['fieldname' => '/tmp/path' (real upload)]. */
function api_post($path, $fields = [], $files = [], $method = 'POST') {
    $ch = curl_init(ENGINE . $path);
    $post = $fields;
    foreach ($files as $name => $f) {
        if ($f && is_file($f['tmp_name']) && $f['error'] === 0) {
            $post[$name] = new CURLFile($f['tmp_name'], $f['type'], $f['name']);
        }
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 120,
    ]);
    $body = curl_exec($ch);
    if ($body === false) return ['__error' => 'Engine not reachable: ' . curl_error($ch)];
    return json_decode($body, true) ?: ['__error' => 'Bad response'];
}

/** POST a JSON body to the engine (for endpoints expecting application/json). */
function api_post_json($path, $data = []) {
    $ch = curl_init(ENGINE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    if ($body === false) return ['__error' => 'Engine not reachable: ' . curl_error($ch)];
    return json_decode($body, true) ?: ['__error' => 'Bad response'];
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function layout_head($title) {
    $nav = [
        'index.php'     => 'Dashboard',
        'accounts.php'  => 'Accounts',
        'contacts.php'  => 'Contacts',
        'campaigns.php' => 'Campaigns',
        'settings.php'  => 'Settings',
    ];
    $cur = basename($_SERVER['PHP_SELF']);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' — BulkWPSender</title>';
    echo '<script>window.ENGINE=' . json_encode(ENGINE) . ';</script>';
    echo '<link rel="stylesheet" href="assets/style.css"></head><body>';
    echo '<header><div class="brand">📤 BulkWPSender</div><nav>';
    foreach ($nav as $file => $label) {
        $active = ($file === $cur || ($cur === 'campaign.php' && $file === 'campaigns.php')) ? ' class="active"' : '';
        echo '<a' . $active . ' href="' . $file . '">' . h($label) . '</a>';
    }
    echo '</nav></header><main>';
}

function layout_foot() {
    echo '</main><footer>Local tool · whatsapp-web.js · <span id="appVer"></span> Use responsibly — only message contacts who opted in.</footer>';
    echo '<script>fetch(window.ENGINE+"/api/version").then(r=>r.json()).then(v=>{if(v.current)document.getElementById("appVer").textContent="v"+v.current+" · ";}).catch(()=>{});</script>';
    echo '</body></html>';
}

function flash($msg, $type = 'ok') {
    echo '<div class="flash ' . h($type) . '">' . h($msg) . '</div>';
}
