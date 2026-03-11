<?php
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // GET requests are silently redirected — not logged out
    header('Location: ' . (isLoggedIn() ? '/dashboard.php' : '/login.php'));
    exit;
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();
header('Location: /login.php');
exit;
