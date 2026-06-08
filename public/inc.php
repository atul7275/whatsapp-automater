<?php
// Shared config + helpers for the PHP control panel.
// The PHP side is a thin UI: it talks to the Node engine over HTTP.

// Use 127.0.0.1 (not "localhost"): on Windows "localhost" can resolve to IPv6
// (::1) first, but the engine binds IPv4 loopback — causing connection failures.
define('ENGINE', getenv('ENGINE_URL') ?: 'http://127.0.0.1:3000');

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

function nav_icon($key) {
    // minimal inline SVG icons (stroke), 20x20
    $p = [
        'index.php'     => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'accounts.php'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'contacts.php'  => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>',
        'campaigns.php' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
        'settings.php'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ($p[$key] ?? '') . '</svg>';
}

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
    echo '<title>' . h($title) . ' · BulkWPSender</title>';
    echo '<script>window.ENGINE=' . json_encode(ENGINE) . ';</script>';
    echo '<link rel="stylesheet" href="assets/style.css"></head><body>';
    echo '<div class="app">';
    echo '<aside class="sidebar"><div class="logo"><span class="logo-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></span><span class="logo-text">BulkWPSender</span></div><nav>';
    foreach ($nav as $file => $label) {
        $active = ($file === $cur || ($cur === 'campaign.php' && $file === 'campaigns.php')) ? ' class="active"' : '';
        echo '<a' . $active . ' href="' . $file . '">' . nav_icon($file) . '<span>' . h($label) . '</span></a>';
    }
    echo '</nav><div class="sidebar-foot"><span id="appVer">v—</span><span id="connDot" class="dot off" title="engine status"></span></div></aside>';
    echo '<div class="content"><header class="topbar"><h2>' . h($title) . '</h2></header><main>';
}

function layout_foot() {
    echo '</main></div></div>';
    echo '<script>(function(){fetch(window.ENGINE+"/api/version").then(r=>r.json()).then(v=>{var a=document.getElementById("appVer");if(a&&v.current)a.textContent="v"+v.current;var d=document.getElementById("connDot");if(d)d.className="dot on";}).catch(function(){var d=document.getElementById("connDot");if(d)d.className="dot off";});})();</script>';
    echo '</body></html>';
}

function flash($msg, $type = 'ok') {
    echo '<div class="flash ' . h($type) . '">' . h($msg) . '</div>';
}
