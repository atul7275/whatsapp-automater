<?php
// Shared config + helpers for the PHP control panel.
// The PHP side is a thin UI: it talks to the Node engine over HTTP.

// The panel needs PHP's curl extension. If php.ini is misconfigured it won't be
// loaded — show a clear fix instead of a fatal "undefined function" crash.
if (!function_exists('curl_init')) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>BulkWPSender — setup needed</title>'
       . '<div style="font-family:system-ui;max-width:620px;margin:60px auto;padding:24px;border:1px solid #ddd;border-radius:12px">'
       . '<h2>⚠ PHP curl extension is not enabled</h2>'
       . '<p>The control panel needs PHP\'s <code>curl</code> extension, which isn\'t loading.</p>'
       . '<p><strong>Fix it in one step:</strong> run <code>Troubleshoot.bat</code> in your install '
       . 'folder (or Start Menu → <em>Troubleshoot BulkWPSender</em>). It rewrites the PHP config and restarts.</p>'
       . '<p class="muted">Then reopen this page.</p></div>';
    exit;
}

// Use 127.0.0.1 (not "localhost"): on Windows "localhost" can resolve to IPv6
// (::1) first, but the engine binds IPv4 loopback — causing connection failures.
define('ENGINE', getenv('ENGINE_URL') ?: 'http://127.0.0.1:3000');

// Once a call fails to connect, remember it for this page render so the other
// calls on the same page return instantly instead of each waiting to time out.
$GLOBALS['__engine_down'] = false;

function api_common_opts($ch) {
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1200,   // fail fast if the engine is down
        CURLOPT_NOSIGNAL => true,
    ]);
}
function api_fail($ch) {
    // connection-level failures mean the engine is down — short-circuit the rest
    $errno = curl_errno($ch);
    if (in_array($errno, [CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_RESOLVE_HOST], true)) {
        $GLOBALS['__engine_down'] = true;
    }
    return ['__error' => 'Engine not reachable: ' . curl_error($ch)];
}

/** GET JSON from the engine. Returns decoded array or ['__error'=>msg]. */
function api_get($path) {
    if ($GLOBALS['__engine_down']) return ['__error' => 'Engine not reachable'];
    $ch = curl_init(ENGINE . $path);
    api_common_opts($ch);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $body = curl_exec($ch);
    if ($body === false) return api_fail($ch);
    return json_decode($body, true) ?: ['__error' => 'Bad response'];
}

/** POST to the engine. $files = ['fieldname' => '/tmp/path' (real upload)]. */
function api_post($path, $fields = [], $files = [], $method = 'POST') {
    if ($GLOBALS['__engine_down']) return ['__error' => 'Engine not reachable'];
    $ch = curl_init(ENGINE . $path);
    $post = $fields;
    foreach ($files as $name => $f) {
        if ($f && is_file($f['tmp_name']) && $f['error'] === 0) {
            $post[$name] = new CURLFile($f['tmp_name'], $f['type'], $f['name']);
        }
    }
    api_common_opts($ch);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 120,
    ]);
    $body = curl_exec($ch);
    if ($body === false) return api_fail($ch);
    return json_decode($body, true) ?: ['__error' => 'Bad response'];
}

/** POST a JSON body to the engine (for endpoints expecting application/json). */
function api_post_json($path, $data = []) {
    if ($GLOBALS['__engine_down']) return ['__error' => 'Engine not reachable'];
    $ch = curl_init(ENGINE . $path);
    api_common_opts($ch);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    if ($body === false) return api_fail($ch);
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
    $lock = !empty($_SESSION['bw_auth']) ? '<a href="?__logout=1" class="lock" title="Lock panel">&#128274;</a>' : '';
    echo '</nav><div class="sidebar-foot"><span id="appVer">v—</span><span id="connDot" class="dot off" title="engine status"></span>' . $lock . '</div></aside>';
    echo '<div class="content"><header class="topbar"><h2>' . h($title) . '</h2></header><main><div id="updateBanner"></div>';
}

function layout_foot() {
    echo '</main></div></div>';
    // Global: show engine version + status, and a one-click update notice on every page.
    echo <<<'JS'
<script>(function(){
  fetch(window.ENGINE+"/api/version").then(function(r){return r.json();}).then(function(v){
    var a=document.getElementById("appVer"); if(a&&v.current)a.textContent="v"+v.current;
    var d=document.getElementById("connDot"); if(d)d.className="dot on";
    if(v.updateAvailable){
      var el=document.getElementById("updateBanner"); if(!el)return;
      el.innerHTML='<div class="update-bar"><span>✨ <strong>New version v'+v.latest+'</strong> available (you have v'+v.current+'). It updates in place and keeps all your data.</span><span class="ub-actions"><button class="btn small" id="bwUpd">Update now</button> <a class="btn ghost small" href="'+v.url+'" target="_blank">Release notes</a></span></div>';
      document.getElementById("bwUpd").onclick=function(){
        if(!confirm("Update to v"+v.latest+"?\n\nThe app will stop, install the new version in place (your accounts, contacts, lists and history are kept), and reopen automatically."))return;
        var b=this; b.disabled=true; b.textContent="Updating…";
        fetch(window.ENGINE+"/api/update",{method:"POST",headers:{"Content-Type":"application/json"},body:"{}"})
          .then(function(r){return r.json();}).then(function(d){
            if(d.error){b.disabled=false;b.textContent="Update now";alert("Update failed: "+d.error+"\n\nYou can also download it from the release page.");}
            else{b.textContent="Updating… the app will reopen shortly.";}
          }).catch(function(e){b.disabled=false;b.textContent="Update now";alert("Update failed: "+e.message);});
      };
    }
  }).catch(function(){var d=document.getElementById("connDot");if(d)d.className="dot off";});
})();</script>
JS;
    echo '</body></html>';
}

function flash($msg, $type = 'ok') {
    echo '<div class="flash ' . h($type) . '">' . h($msg) . '</div>';
}

/* ---- optional panel password gate ------------------------------------- */
function login_page($err = '') {
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Sign in · BulkWPSender</title>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<link rel="stylesheet" href="assets/style.css"></head><body class="login-body">';
    echo '<form method="post" class="login-card">';
    echo '<div class="login-logo"><span class="logo-mark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></span> BulkWPSender</div>';
    if ($err) echo '<div class="flash err">' . h($err) . '</div>';
    echo '<label>Password<input type="password" name="__password" autofocus required></label>';
    echo '<input type="hidden" name="__login" value="1">';
    echo '<button class="btn" style="width:100%;justify-content:center;margin-top:10px">Sign in</button>';
    echo '</form></body></html>';
}

function panel_gate() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_GET['__logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php'); exit; }
    if (!empty($_SESSION['bw_auth'])) return;
    $s = api_get('/api/settings');
    if (isset($s['__error']) || empty($s['panel_password_set'])) return; // no gate (or engine down)
    if (($_POST['__login'] ?? '') === '1') {
        $r = api_post_json('/api/auth/check', ['password' => $_POST['__password'] ?? '']);
        if (!empty($r['ok'])) { $_SESSION['bw_auth'] = true; header('Location: ' . $_SERVER['REQUEST_URI']); exit; }
        login_page('Incorrect password.'); exit;
    }
    login_page(); exit;
}

panel_gate();
