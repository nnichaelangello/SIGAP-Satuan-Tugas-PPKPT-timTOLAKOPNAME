<?php
/**
 * SIGAP PPKS - Get Psikolog's Assigned Cases
 * GET: Returns cases assigned to the logged-in psikolog
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

error_reporting(0);
ini_set('display_errors', 0);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

if (!isset($_SESSION['psikolog_logged_in']) || $_SESSION['psikolog_logged_in'] !== true) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    $psikologId = $_SESSION['psikolog_id'];

    // Filter by status if provided
    $statusFilter = $_GET['status'] ?? '';
    $validStatuses = ['Dijadwalkan', 'Konsultasi', 'Menunggu_Konfirmasi', 'Dispute', 'Closed'];

    $whereClause = "WHERE l.assigned_psikolog_id = :psikolog_id";
    $params = [':psikolog_id' => $psikologId];

    if (!empty($statusFilter) && in_array($statusFilter, $validStatuses)) {
        $whereClause .= " AND l.status_laporan = :status";
        $params[':status'] = $statusFilter;
    }

    $query = "SELECT 
        l.id,
        l.kode_pelaporan,
        l.status_laporan,
        l.status_darurat,
        l.korban_sebagai,
        l.gender_korban,
        l.waktu_kejadian,
        l.lokasi_kejadian,
        l.tingkat_kekhawatiran,
        l.dispute_count,
        l.created_at,
        l.updated_at,
        j.id as jadwal_id,
        j.waktu_mulai,
        j.waktu_selesai,
        j.tipe as tipe_pertemuan,
        j.tempat_atau_link,
        j.status_jadwal,
        ck.id as catatan_id,
        ck.status_catatan,
        ck.tingkat_risiko,
        ck.created_at as catatan_created_at
    FROM Laporan l
    LEFT JOIN JadwalPertemuan j ON j.laporan_id = l.id AND j.psikolog_id = :psikolog_id2 AND j.status_jadwal != 'cancelled'
    LEFT JOIN CatatanKonsultasi ck ON ck.laporan_id = l.id AND ck.psikolog_id = :psikolog_id3
    $whereClause
    ORDER BY 
        CASE l.status_laporan
            WHEN 'Dispute' THEN 1
            WHEN 'Konsultasi' THEN 2
            WHEN 'Dijadwalkan' THEN 3
            WHEN 'Menunggu_Konfirmasi' THEN 4
            WHEN 'Closed' THEN 5
            ELSE 6
        END,
        l.updated_at DESC";

    $params[':psikolog_id2'] = $psikologId;
    $params[':psikolog_id3'] = $psikologId;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $statsQuery = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status_laporan IN ('Dijadwalkan','Konsultasi') THEN 1 ELSE 0 END) as aktif,
        SUM(CASE WHEN status_laporan = 'Menunggu_Konfirmasi' THEN 1 ELSE 0 END) as menunggu,
        SUM(CASE WHEN status_laporan = 'Dispute' THEN 1 ELSE 0 END) as dispute,
        SUM(CASE WHEN status_laporan = 'Closed' THEN 1 ELSE 0 END) as selesai
    FROM Laporan WHERE assigned_psikolog_id = :pid";
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute([':pid' => $psikologId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $cases,
        'statistics' => $stats,
        'csrf_token' => $_SESSION['csrf_token'] ?? ''
    ]);

}
catch (Exception $e) {
    error_log("Get psikolog cases error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil data kasus']);
}
exit;
