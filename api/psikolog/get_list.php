<?php
/**
 * SIGAP PPKS - Get Active Psikolog List (Admin use)
 * GET: Returns all active psychologists
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();

    $query = "SELECT 
        p.id, p.nama_lengkap, p.email, p.spesialisasi, p.foto_url, p.status, p.last_login,
        COUNT(DISTINCT CASE WHEN l.status_laporan NOT IN ('Closed','Ditolak') THEN l.id END) as active_cases
    FROM Psikolog p
    LEFT JOIN Laporan l ON l.assigned_psikolog_id = p.id
    WHERE p.status = 'aktif'
    GROUP BY p.id
    ORDER BY p.nama_lengkap";

    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $psikologList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $psikologList,
        'total' => count($psikologList)
    ]);

}
catch (Exception $e) {
    error_log("Get psikolog list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil data psikolog']);
}
exit;
