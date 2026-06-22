<?php
require_once __DIR__ . '/auth.php';
auth_start_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    auth_clear_session_cookie();
}
session_destroy();
auth_redirect(auth_url('/'));
