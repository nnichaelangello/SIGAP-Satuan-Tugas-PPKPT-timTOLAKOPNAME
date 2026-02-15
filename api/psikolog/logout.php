<?php
/**
 * SIGAP PPKS - Psikolog Logout API
 */
header('Content-Type: application/json; charset=utf-8');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

echo json_encode(['status' => 'success', 'message' => 'Logout berhasil']);
exit;
