<?php
/**
 * SIGAP PPKS - Psikolog Respond to Dispute
 * POST: { feedback_id, catatan_id, respon_psikolog, ringkasan_kasus, detail_konsultasi, rekomendasi, tingkat_risiko, csrf_token }
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/psikolog_error.log');

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);

if (!isset($_SESSION['psikolog_logged_in']) || $_SESSION['psikolog_logged_in'] !== true) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input)
    $input = $_POST;

// CSRF
if (empty($input['csrf_token']) || ($input['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid security token']));
}

$feedbackId = filter_var($input['feedback_id'] ?? '', FILTER_VALIDATE_INT);
$catatanId = filter_var($input['catatan_id'] ?? '', FILTER_VALIDATE_INT);
$responPsikolog = trim($input['respon_psikolog'] ?? '');

if (!$feedbackId || !$catatanId || empty($responPsikolog)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Feedback ID, catatan ID, dan respon wajib diisi']));
}

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
    $psikologId = $_SESSION['psikolog_id'];

    // Verify catatan belongs to this psikolog
    $checkQuery = "SELECT ck.id, ck.laporan_id, l.dispute_count 
                   FROM CatatanKonsultasi ck 
                   JOIN Laporan l ON l.id = ck.laporan_id 
                   WHERE ck.id = :cid AND ck.psikolog_id = :pid LIMIT 1";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([':cid' => $catatanId, ':pid' => $psikologId]);
    $catatan = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$catatan) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Catatan tidak ditemukan atau bukan milik Anda']));
    }

    $laporanId = $catatan['laporan_id'];
    $disputeCount = (int)$catatan['dispute_count'];

    $pdo->beginTransaction();

    // Update feedback with psikolog response
    $pdo->prepare("UPDATE FeedbackUser SET respon_psikolog = :respon, responded_at = NOW() WHERE id = :fid")
        ->execute([':respon' => $responPsikolog, ':fid' => $feedbackId]);

    // Update catatan if new data provided
    $updateFields = [];
    $updateParams = [':cid' => $catatanId];

    if (!empty($input['ringkasan_kasus'])) {
        $updateFields[] = "ringkasan_kasus = :ringkasan";
        $updateParams[':ringkasan'] = trim($input['ringkasan_kasus']);
    }
    if (!empty($input['detail_konsultasi'])) {
        $updateFields[] = "detail_konsultasi = :detail";
        $updateParams[':detail'] = trim($input['detail_konsultasi']);
    }
    if (!empty($input['rekomendasi'])) {
        $updateFields[] = "rekomendasi = :rekomendasi";
        $updateParams[':rekomendasi'] = trim($input['rekomendasi']);
    }
    if (!empty($input['tingkat_risiko'])) {
        $updateFields[] = "tingkat_risiko = :risiko";
        $updateParams[':risiko'] = $input['tingkat_risiko'];
    }

    $updateFields[] = "status_catatan = 'submitted'";

    if (!empty($updateFields)) {
        $pdo->prepare("UPDATE CatatanKonsultasi SET " . implode(', ', $updateFields) . " WHERE id = :cid")
            ->execute($updateParams);
    }

    // Check if dispute limit reached (>=3 disputes → escalate to admin)
    if ($disputeCount >= 3) {
        $newStatus = 'Eskalasi_Admin';
        $keterangan = 'Dispute melebihi batas (3x). Eskalasi ke Admin untuk mediasi.';
    }
    else {
        $newStatus = 'Menunggu_Konfirmasi';
        $keterangan = 'Psikolog merevisi catatan setelah dispute. Menunggu konfirmasi ulang User.';
    }

    // Update laporan status
    $pdo->prepare("UPDATE Laporan SET status_laporan = :status, auto_close_at = DATE_ADD(NOW(), INTERVAL 14 DAY) WHERE id = :lid")
        ->execute([':status' => $newStatus, ':lid' => $laporanId]);

    // Log status change
    $pdo->prepare("INSERT INTO StatusHistory (laporan_id, status_lama, status_baru, diubah_oleh_role, diubah_oleh_id, keterangan) VALUES (:lid, 'Dispute', :new, 'psikolog', :pid, :ket)")
        ->execute([':lid' => $laporanId, ':new' => $newStatus, ':pid' => $psikologId, ':ket' => $keterangan]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => $disputeCount >= 3 ? 'Kasus dieskalasi ke Admin untuk mediasi' : 'Respon berhasil dikirim. Menunggu konfirmasi ulang User.',
        'data' => [
            'laporan_status' => $newStatus,
            'dispute_count' => $disputeCount
        ],
        'csrf_token' => $_SESSION['csrf_token']
    ]);

}
catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    error_log("Respond dispute error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengirim respon']);
}
exit;
