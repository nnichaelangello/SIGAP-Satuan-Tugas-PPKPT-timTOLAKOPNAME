<?php
/**
 * SIGAP PPKS - Get Feedback for a case
 * GET: ?laporan_id=X or ?kode_pelaporan=X&email=X
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
    $kode = trim($_GET['kode_pelaporan'] ?? '');
    $email = trim($_GET['email'] ?? '');

    if (!$laporanId && (empty($kode) || empty($email))) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'laporan_id atau (kode_pelaporan + email) diperlukan']));
    }

    // If using kode + email, resolve laporan_id first
    if (!$laporanId && !empty($kode)) {
        $resolve = $pdo->prepare("SELECT id FROM Laporan WHERE kode_pelaporan = :kode AND email_korban = :email LIMIT 1");
        $resolve->execute([':kode' => $kode, ':email' => $email]);
        $row = $resolve->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            exit(json_encode(['status' => 'error', 'message' => 'Laporan tidak ditemukan']));
        }
        $laporanId = $row['id'];
    }

    // Get feedback with catatan info
    $query = "SELECT 
        f.*,
        ck.ringkasan_kasus,
        ck.detail_konsultasi,
        ck.rekomendasi,
        ck.tingkat_risiko,
        ck.status_catatan,
        p.nama_lengkap as psikolog_nama
    FROM FeedbackUser f
    JOIN CatatanKonsultasi ck ON ck.id = f.catatan_id
    JOIN Psikolog p ON p.id = ck.psikolog_id
    WHERE f.laporan_id = :lid
    ORDER BY f.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute([':lid' => $laporanId]);
    $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $feedback,
        'total' => count($feedback)
    ]);

}
catch (Exception $e) {
    error_log("Get feedback error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil feedback']);
}
exit;
