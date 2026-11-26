<?php
require __DIR__ . '/app/bootstrap.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
setcookie('MAVALBOV', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
session_destroy();
redirect('login.php');
