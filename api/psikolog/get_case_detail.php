<?php
/**
 * SIGAP PPKS - Get Single Case Detail for Psikolog
 * GET: ?id=X
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
    $laporanId = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);

    if (!$laporanId) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'ID Laporan diperlukan']));
    }

    // Query mirip dengan get_cases tapi spesifik ID
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
        ck.ringkasan_kasus,
        ck.detail_konsultasi,
        ck.rekomendasi,
        ck.created_at as catatan_created_at
    FROM Laporan l
    LEFT JOIN JadwalPertemuan j ON j.laporan_id = l.id AND j.status_jadwal != 'cancelled'
    LEFT JOIN CatatanKonsultasi ck ON ck.laporan_id = l.id
    WHERE l.id = :lid AND l.assigned_psikolog_id = :pid
    LIMIT 1";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':lid' => $laporanId, ':pid' => $psikologId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($case) {
        echo json_encode(['status' => 'success', 'data' => $case]);
    }
    else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Kasus tidak ditemukan atau tidak di-assign ke Anda']);
    }

}
catch (Exception $e) {
    error_log("Get case detail error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil detail kasus']);
}
