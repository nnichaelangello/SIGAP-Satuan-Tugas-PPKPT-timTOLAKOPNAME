<?php
/**
 * SIGAP PPKS - Psikolog Login API
 * POST: { email, password }
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS handling
require_once __DIR__ . '/../../config/cors.php';
if (handlePublicCors()) {
    exit; // Preflight handled
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/psikolog_error.log');

require_once __DIR__ . '/../../config/database.php';

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Email dan password wajib diisi']));
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Format email tidak valid']));
}

try {
    $pdo = getDBConnection();

    // Check rate limiting
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rateLimitQuery = "SELECT COUNT(*) as attempts FROM LoginAttempts 
                       WHERE email = :email AND ip_address = :ip AND success = 0 
                       AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
    $rateStmt = $pdo->prepare($rateLimitQuery);
    $rateStmt->execute([':email' => $email, ':ip' => $ipAddress]);
    $rateCheck = $rateStmt->fetch();

    if ($rateCheck['attempts'] >= 5) {
        http_response_code(429);
        exit(json_encode(['status' => 'error', 'message' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.']));
    }

    // Find psikolog
    $query = "SELECT * FROM Psikolog WHERE email = :email AND status = 'aktif' LIMIT 1";
    $stmt = $pdo->prepare($query);
    $stmt->execute([':email' => $email]);
    $psikolog = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$psikolog || !password_verify($password, $psikolog['password_hash'])) {
        // Log failed attempt
        $logQuery = "INSERT INTO LoginAttempts (email, ip_address, success, failure_reason) VALUES (:email, :ip, 0, :reason)";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([
            ':email' => $email,
            ':ip' => $ipAddress,
            ':reason' => !$psikolog ? 'psikolog_not_found' : 'wrong_password'
        ]);

        // Update failed attempts
        if ($psikolog) {
            $pdo->prepare("UPDATE Psikolog SET failed_attempts = failed_attempts + 1 WHERE id = :id")
                ->execute([':id' => $psikolog['id']]);
        }

        http_response_code(401);
        exit(json_encode(['status' => 'error', 'message' => 'Email atau password salah']));
    }

    // Check if account is locked
    if ($psikolog['locked_until'] && strtotime($psikolog['locked_until']) > time()) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Akun terkunci. Coba lagi nanti.']));
    }

    // Successful login - start session
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443,
        'cookie_samesite' => 'Strict'
    ]);

    session_regenerate_id(true);

    $_SESSION['psikolog_logged_in'] = true;
    $_SESSION['psikolog_id'] = $psikolog['id'];
    $_SESSION['psikolog_email'] = $psikolog['email'];
    $_SESSION['psikolog_nama'] = $psikolog['nama_lengkap'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Update login info
    $pdo->prepare("UPDATE Psikolog SET last_login = NOW(), failed_attempts = 0, locked_until = NULL WHERE id = :id")
        ->execute([':id' => $psikolog['id']]);

    // Log successful attempt
    $logQuery = "INSERT INTO LoginAttempts (email, ip_address, success) VALUES (:email, :ip, 1)";
    $logStmt = $pdo->prepare($logQuery);
    $logStmt->execute([':email' => $email, ':ip' => $ipAddress]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Login berhasil',
        'data' => [
            'id' => (int)$psikolog['id'],
            'nama' => $psikolog['nama_lengkap'],
            'email' => $psikolog['email'],
            'spesialisasi' => $psikolog['spesialisasi'],
            'foto_url' => $psikolog['foto_url']
        ],
        'csrf_token' => $_SESSION['csrf_token']
    ]);

}
catch (Exception $e) {
    error_log("Psikolog login error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan server']);
}
exit;
