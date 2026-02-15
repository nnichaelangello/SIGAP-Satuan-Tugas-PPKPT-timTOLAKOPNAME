<?php
/**
 * SIGAP PPKS - Get Schedule
 * GET: ?laporan_id=X or ?psikolog_id=X
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

    $laporanId = filter_var($_GET['laporan_id'] ?? '', FILTER_VALIDATE_INT);
    $psikologId = filter_var($_GET['psikolog_id'] ?? '', FILTER_VALIDATE_INT);
    $kode = trim($_GET['kode_pelaporan'] ?? '');

    if (!$laporanId && !$psikologId && empty($kode)) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'laporan_id, psikolog_id, atau kode_pelaporan diperlukan']));
    }

    $whereClause = "";
    $params = [];

    if ($laporanId) {
        $whereClause = "j.laporan_id = :lid";
        $params[':lid'] = $laporanId;
    }
    elseif (!empty($kode)) {
        $whereClause = "l.kode_pelaporan = :kode";
        $params[':kode'] = $kode;
    }
    else {
        $whereClause = "j.psikolog_id = :pid";
        $params[':pid'] = $psikologId;
    }

    $query = "SELECT 
        j.*,
        p.nama_lengkap as psikolog_nama,
        p.spesialisasi as psikolog_spesialisasi,
        p.foto_url as psikolog_foto,
        l.kode_pelaporan,
        l.status_laporan
    FROM JadwalPertemuan j
    JOIN Psikolog p ON p.id = j.psikolog_id
    JOIN Laporan l ON l.id = j.laporan_id
    WHERE $whereClause
    ORDER BY j.waktu_mulai DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $schedules,
        'total' => count($schedules)
    ]);

}
catch (Exception $e) {
    error_log("Get schedule error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil jadwal']);
}
exit;
