<?php
/**
 * SIGAP PPKS - API Get Active Psikologs
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';

if (handlePublicCors())
    exit;

try {
    $pdo = getDBConnection();

    // Get active psikologs
    $stmt = $pdo->prepare("SELECT id, nama_lengkap, spesialisasi FROM Psikolog WHERE status = 'aktif' ORDER BY nama_lengkap ASC");
    $stmt->execute();
    $psikologs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $psikologs
    ]);
}
catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
