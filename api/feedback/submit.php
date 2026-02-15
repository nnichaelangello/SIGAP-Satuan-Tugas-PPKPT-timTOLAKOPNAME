<?php
/**
 * SIGAP PPKS - API Submit Feedback User
 * POST: Memberikan konfirmasi atau dispute terhadap catatan psikolog
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../services/BlockchainService.php';
require_once __DIR__ . '/../services/MailerService.php';

header('Content-Type: application/json; charset=utf-8');

if (handlePublicCors())
    exit;

try {
    $pdo = getDBConnection();

    // Read Input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input)
        throw new Exception("Invalid JSON input");

    // Validate
    if (empty($input['kode_pelaporan']) || empty($input['catatan_id'])) {
        throw new Exception("Kode Laporan dan Catatan ID wajib diisi");
    }

    // Resolve Laporan ID from Kode
    $stmtCode = $pdo->prepare("SELECT id, status_laporan, email_korban FROM Laporan WHERE kode_pelaporan = ? LIMIT 1");
    $stmtCode->execute([$input['kode_pelaporan']]);
    $laporan = $stmtCode->fetch(PDO::FETCH_ASSOC);

    if (!$laporan) {
        throw new Exception("Laporan tidak ditemukan");
    }

    $laporanId = (int)$laporan['id'];
    $catatanId = (int)$input['catatan_id'];
    $oldStatus = $laporan['status_laporan'];
    $tipe = $input['tipe_feedback'] ?? $input['tipe']; // Support both keys
    $komentar = trim($input['komentar_user'] ?? $input['komentar'] ?? '');
    $detailDispute = trim($input['detail_dispute'] ?? '');
    $emailVerif = trim($input['email'] ?? '');

    // Verify Email (Optional but recommended security)
    if (!empty($emailVerif)) {
        if (strtolower($laporan['email_korban']) !== strtolower($emailVerif)) {
            throw new Exception("Email tidak cocok dengan data laporan ini.");
        }
    }

    if (!in_array($tipe, ['confirm', 'dispute'])) {
        throw new Exception("Tipe feedback tidak valid");
    }

    if ($tipe === 'dispute' && empty($detailDispute)) {
        throw new Exception("Detail keberatan wajib diisi jika mengajukan dispute");
    }

    // Transaction
    $pdo->beginTransaction();

    // 1. Insert FeedbackUser
    $stmt = $pdo->prepare("
        INSERT INTO FeedbackUser (
            laporan_id, catatan_id, tipe_feedback, 
            komentar_user, detail_dispute, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, NOW()
        )
    ");

    $stmt->execute([
        $laporanId, $catatanId, $tipe,
        $komentar, ($tipe === 'dispute' ? $detailDispute : null)
    ]);

    $feedbackId = $pdo->lastInsertId();

    // 2. Determine New Statuses
    $statusLaporan = ($tipe === 'confirm') ? 'Closed' : 'Dispute';
    $statusCatatan = ($tipe === 'confirm') ? 'confirmed' : 'disputed';

    // 3. Update Laporan Status
    // Increment dispute count if disputed
    $sqlLaporan = "
        UPDATE Laporan 
        SET 
            status_laporan = :status, 
            updated_at = NOW() 
            " . ($tipe === 'dispute' ? ", dispute_count = dispute_count + 1" : "") . "
        WHERE id = :id
    ";

    $stmtUpdate = $pdo->prepare($sqlLaporan);
    $stmtUpdate->execute([':status' => $statusLaporan, ':id' => $laporanId]);

    // 4. Update Catatan Status
    $stmtCatatan = $pdo->prepare("
        UPDATE CatatanKonsultasi 
        SET status_catatan = :status, updated_at = NOW()
        WHERE id = :id
    ");
    $stmtCatatan->execute([':status' => $statusCatatan, ':id' => $catatanId]);

    // 5. Log History
    $stmtLog = $pdo->prepare("
        INSERT INTO StatusHistory (
            laporan_id, status_lama, status_baru, 
            diubah_oleh_role, keterangan, created_at
        ) VALUES (
            ?, ?, ?, 'user', ?, NOW()
        )
    ");

    $ket = ($tipe === 'confirm') ? 'User menyetujui hasil konsultasi. Kasus ditutup.' : 'User mengajukan keberatan: ' . substr($detailDispute, 0, 50) . '...';

    $stmtLog->execute([$laporanId, $oldStatus, $statusLaporan, $ket]);

    $pdo->commit();

    // Send Email Receipt to User (Gratis via Gmail SMTP)
    try {
        if (!empty($laporan['email_korban'])) {
            $statusDisplay = ($tipe === 'confirm') ? "Selesai (Closed)" : "Sanggahan Diajukan";
            $emailSubject = "[SIGAP PPKPT] Konfirmasi Feedback Laporan #" . $input['kode_pelaporan'];
            $emailBody = "
                <div style='font-family: Arial, sans-serif; color: #333;'>
                    <h2 style='color: #0d9488;'>Umpan Balik Diterima</h2>
                    <p>Halo,</p>
                    <p>Terima kasih telah memberikan konfirmasi untuk laporan <strong>" . htmlspecialchars($input['kode_pelaporan']) . "</strong>.</p>
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 5px 0;'><strong>Tipe:</strong> " . strtoupper($tipe) . "</p>
                        <p style='margin: 5px 0;'><strong>Status Kasus:</strong> " . $statusDisplay . "</p>
                        <p style='margin: 5px 0;'><strong>Komentar:</strong> " . htmlspecialchars($komentar) . "</p>
                    </div>
                </div>
            ";

            MailerService::send($laporan['email_korban'], $emailSubject, $emailBody);
        }
    }
    catch (Exception $mailErr) {
        error_log("Email Receipt Failed: " . $mailErr->getMessage());
    }

    // Blockchain Logging
    try {
        $payload = json_encode($input);
        $hash = hash('sha256', $payload);

        // Use input['kode_pelaporan'] directly as it's validated above
        BlockchainService::addLog($input['kode_pelaporan'], 'FEEDBACK', $hash, $payload, 'USER');
    }
    catch (Exception $e) {
        error_log("Blockchain Log Failed: " . $e->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'message' => ($tipe === 'confirm') ? 'Terima kasih! Laporan Anda telah diselesaikan.' : 'Keberatan Anda telah dikirim ke Psikolog.',
        'data' => ['status_baru' => $statusLaporan]
    ]);


}
catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
