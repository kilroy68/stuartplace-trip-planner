<?php
require_once __DIR__ . '/auth.php';

if (!auth_is_configured()) {
    auth_setup_message();
}

$next = $_GET['next'] ?? '/california-trip/';
if (!is_string($next) || $next === '' || $next[0] !== '/') {
    $next = '/california-trip/';
}

if (auth_is_logged_in()) {
    auth_redirect($next);
}

$c = auth_config();
$state = bin2hex(random_bytes(24));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_next'] = $next;

$params = [
    'client_id' => $c['google_client_id'],
    'redirect_uri' => auth_url('/auth/callback.php'),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account',
    'access_type' => 'online',
];
$googleUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in - Stuart Place</title>
<style>
:root{--ink:#102024;--muted:#5d6d72;--cream:#fff8ec;--ocean:#0f6c81;--orange:#d46b38}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--ink);background:radial-gradient(circle at 18% 20%,rgba(212,107,56,.22),transparent 28%),radial-gradient(circle at 80% 12%,rgba(15,108,129,.2),transparent 26%),linear-gradient(135deg,#fff8ec,#f1dfc2 55%,#cfe6e9)}.card{width:min(520px,calc(100% - 36px));background:rgba(255,255,255,.86);border:1px solid rgba(255,255,255,.8);border-radius:28px;padding:34px;box-shadow:0 28px 90px rgba(16,32,36,.18);backdrop-filter:blur(16px)}h1{font-size:34px;margin:0 0 10px;letter-spacing:-.04em}p{line-height:1.5;color:var(--muted)}.button{display:flex;align-items:center;justify-content:center;gap:12px;width:100%;min-height:52px;border-radius:999px;border:1px solid rgba(16,32,36,.15);background:white;color:var(--ink);font-weight:850;text-decoration:none;box-shadow:0 10px 28px rgba(16,32,36,.1)}.button:hover{border-color:rgba(15,108,129,.45)}.g{font-size:22px;font-weight:900;color:#4285f4}.small{font-size:13px;margin-top:18px}</style>
</head>
<body>
<main class="card">
  <h1>Sign in to Stuart Place</h1>
  <p>This page is private. Sign in with an allowed Google account to continue.</p>
  <a class="button" href="<?= auth_h($googleUrl) ?>"><span class="g">G</span> Continue with Google</a>
  <p class="small">Initially allowed: david.c.stuart@gmail.com and angelarx@gmail.com.</p>
</main>
</body>
</html>
