<?php
/**
 * SIGAP PPKS - Submit Consultation Notes
 * POST: { laporan_id, jadwal_id, ringkasan_kasus, detail_konsultasi, rekomendasi, tingkat_risiko, action (draft|submit), csrf_token }
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

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

if (!isset($_SESSION['psikolog_logged_in']) || $_SESSION['psikolog_logged_in'] !== true) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input)
    $input = $_POST;

// CSRF check
$csrfToken = $input['csrf_token'] ?? '';
if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid security token']));
}

// Validate required fields
$laporanId = filter_var($input['laporan_id'] ?? '', FILTER_VALIDATE_INT);
$jadwalId = filter_var($input['jadwal_id'] ?? '', FILTER_VALIDATE_INT);
$ringkasan = trim($input['ringkasan_kasus'] ?? '');
$detail = trim($input['detail_konsultasi'] ?? '');
$rekomendasi = trim($input['rekomendasi'] ?? '');
$tingkatRisiko = $input['tingkat_risiko'] ?? 'sedang';
$action = $input['action'] ?? 'draft'; // draft or submit
$catatanId = filter_var($input['catatan_id'] ?? '', FILTER_VALIDATE_INT); // for updating existing

if (!$laporanId || empty($ringkasan) || empty($detail)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Laporan ID, ringkasan, dan detail konsultasi wajib diisi']));
}

$validRisiko = ['rendah', 'sedang', 'tinggi', 'kritis'];
if (!in_array($tingkatRisiko, $validRisiko)) {
    $tingkatRisiko = 'sedang';
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/BlockchainService.php';
require_once __DIR__ . '/../services/MailerService.php';

try {
    $pdo = getDBConnection();
    $psikologId = $_SESSION['psikolog_id'];

    // Verify this case is assigned to this psikolog
    $checkQuery = "SELECT id, kode_pelaporan, status_laporan, assigned_psikolog_id, email_korban FROM Laporan WHERE id = :lid AND assigned_psikolog_id = :pid LIMIT 1";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([':lid' => $laporanId, ':pid' => $psikologId]);
    $laporan = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$laporan) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'Kasus ini tidak di-assign kepada Anda']));
    }

    $statusCatatan = ($action === 'submit') ? 'submitted' : 'draft';

    // Variables for audit
    $diff = null;

    $pdo->beginTransaction();

    if ($catatanId) {
        // Fetch existing data for audit diff
        $stmtGetOld = $pdo->prepare("SELECT ringkasan_kasus, detail_konsultasi, rekomendasi, tingkat_risiko FROM CatatanKonsultasi WHERE id = :cid");
        $stmtGetOld->execute([':cid' => $catatanId]);
        $oldNote = $stmtGetOld->fetch(PDO::FETCH_ASSOC);

        // Update existing note
        $updateQuery = "UPDATE CatatanKonsultasi SET 
            ringkasan_kasus = :ringkasan,
            detail_konsultasi = :detail,
            rekomendasi = :rekomendasi,
            tingkat_risiko = :risiko,
            status_catatan = :status
            WHERE id = :cid AND psikolog_id = :pid AND laporan_id = :lid";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([
            ':ringkasan' => $ringkasan,
            ':detail' => $detail,
            ':rekomendasi' => $rekomendasi,
            ':risiko' => $tingkatRisiko,
            ':status' => $statusCatatan,
            ':cid' => $catatanId,
            ':pid' => $psikologId,
            ':lid' => $laporanId
        ]);
        $noteId = $catatanId;

        // Calculate diff
        if ($oldNote) {
            $changes = [];
            if ($oldNote['ringkasan_kasus'] !== $ringkasan)
                $changes[] = ['field' => 'Ringkasan', 'old' => $oldNote['ringkasan_kasus'], 'new' => $ringkasan];
            if ($oldNote['detail_konsultasi'] !== $detail)
                $changes[] = ['field' => 'Detail', 'old' => $oldNote['detail_konsultasi'], 'new' => $detail];
            if ($oldNote['rekomendasi'] !== $rekomendasi)
                $changes[] = ['field' => 'Rekomendasi', 'old' => $oldNote['rekomendasi'], 'new' => $rekomendasi];
            if ($oldNote['tingkat_risiko'] !== $tingkatRisiko)
                $changes[] = ['field' => 'Risiko', 'old' => $oldNote['tingkat_risiko'], 'new' => $tingkatRisiko];

            if (!empty($changes)) {
                $diff = json_encode($changes);
            }
        }
    }
    else {
        // Insert new note
        $insertQuery = "INSERT INTO CatatanKonsultasi 
            (laporan_id, psikolog_id, jadwal_id, ringkasan_kasus, detail_konsultasi, rekomendasi, tingkat_risiko, status_catatan) 
            VALUES (:lid, :pid, :jid, :ringkasan, :detail, :rekomendasi, :risiko, :status)";
        $insertStmt = $pdo->prepare($insertQuery);
        $insertStmt->execute([
            ':lid' => $laporanId,
            ':pid' => $psikologId,
            ':jid' => $jadwalId ?: null,
            ':ringkasan' => $ringkasan,
            ':detail' => $detail,
            ':rekomendasi' => $rekomendasi,
            ':risiko' => $tingkatRisiko,
            ':status' => $statusCatatan
        ]);
        $noteId = $pdo->lastInsertId();

        // For new notes, the diff is "Everything is new"
        $diff = json_encode([
            ['field' => 'Ringkasan', 'old' => '(Baru)', 'new' => $ringkasan],
            ['field' => 'Detail', 'old' => '(Baru)', 'new' => $detail],
            ['field' => 'Rekomendasi', 'old' => '(Baru)', 'new' => $rekomendasi],
            ['field' => 'Risiko', 'old' => '(Baru)', 'new' => $tingkatRisiko]
        ]);
    }

    // If submitting (not draft), update laporan status
    if ($action === 'submit') {
        $oldStatus = $laporan['status_laporan'];
        $newStatus = 'Menunggu_Konfirmasi';

        $pdo->prepare("UPDATE Laporan SET status_laporan = :status, auto_close_at = DATE_ADD(NOW(), INTERVAL 14 DAY) WHERE id = :lid")
            ->execute([':status' => $newStatus, ':lid' => $laporanId]);

        // Determine log message
        $logMsg = 'Catatan konsultasi disubmit oleh psikolog';
        // If it's an update (catatanId exists) AND diff exists, clarify it's an update
        if ($catatanId && $diff) {
            $logMsg = 'Psikolog memperbarui catatan konsultasi';
        }

        // Log status change
        $pdo->prepare("INSERT INTO StatusHistory (laporan_id, status_lama, status_baru, diubah_oleh_role, diubah_oleh_id, keterangan, perubahan_data) VALUES (:lid, :old, :new, 'psikolog', :pid, :ket, :diff)")
            ->execute([
            ':lid' => $laporanId,
            ':old' => $oldStatus,
            ':new' => $newStatus,
            ':pid' => $psikologId,
            ':ket' => $logMsg,
            ':diff' => $diff
        ]);



        // Blockchain Logging
        try {
            $payload = json_encode([
                'ringkasan' => $ringkasan,
                'detail' => $detail,
                'rekomendasi' => $rekomendasi,
                'risiko' => $tingkatRisiko,
                'diff' => $diff
            ]);
            $hash = hash('sha256', $payload);

            BlockchainService::addLog($laporan['kode_pelaporan'], 'NOTE_PSIKOLOG', $hash, $payload, 'PSIKOLOG');
        }
        catch (Exception $e) {
            error_log("Blockchain Log Failed: " . $e->getMessage());
        }

        // Email Notification to User (Gratis via Gmail SMTP)
        try {
            if (!empty($laporan['email_korban'])) {
                $statusDisplay = ($statusCatatan === 'submitted') ? "Menunggu Konfirmasi" : "Konsultasi Selesai";
                $emailSubject = "[SIGAP PPKPT] Psikolog Memperbarui Tiket #" . $laporan['kode_pelaporan'];
                $emailBody = "
                    <div style='font-family: Arial, sans-serif; color: #333;'>
                        <h2 style='color: #0d9488;'>Pembaruan dari Psikolog</h2>
                        <p>Halo,</p>
                        <p>Psikolog telah memperbarui catatan konsultasi untuk laporan <strong>" . $laporan['kode_pelaporan'] . "</strong>.</p>
                        <div style='background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                            <p style='margin: 5px 0;'><strong>Status:</strong> " . $statusDisplay . "</p>
                            <p style='margin: 5px 0;'><strong>Ringkasan:</strong> " . htmlspecialchars($ringkasan) . "</p>
                        </div>
                        <p>Mohon segera login ke menu <strong>Monitoring</strong> untuk meninjau dan memberikan konfirmasi/persetujuan.</p>
                        <p style='color: #d97706;'><strong>PENTING:</strong> Laporan tidak akan ditutup (Closed) sampai Anda memberikan konfirmasi.</p>
                        <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                        <p style='font-size: 0.9em; color: #666;'><i>Ini adalah pesan otomatis.</i></p>
                    </div>
                ";

                MailerService::send($laporan['email_korban'], $emailSubject, $emailBody);
            }
        }
        catch (Exception $mailErr) {
            error_log("Email Notification Failed: " . $mailErr->getMessage());
        }

        // Mark jadwal as completed
        if ($jadwalId) {
            $pdo->prepare("UPDATE JadwalPertemuan SET status_jadwal = 'completed' WHERE id = :jid")
                ->execute([':jid' => $jadwalId]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => $action === 'submit' ? 'Catatan berhasil disubmit ke User' : 'Draft berhasil disimpan',
        'data' => [
            'catatan_id' => (int)$noteId,
            'status_catatan' => $statusCatatan,
            'laporan_status' => $action === 'submit' ? 'Menunggu_Konfirmasi' : $laporan['status_laporan']
        ],
        'csrf_token' => $_SESSION['csrf_token']
    ]);

}
catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    error_log("Submit notes error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan catatan']);
}
exit;
