<?php
/**
 * SIGAP PPKS - API Schedule Meeting (Main Endpoint)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../services/BlockchainService.php';
require_once __DIR__ . '/../services/MailerService.php';

header('Content-Type: application/json; charset=utf-8');

if (handlePublicCors())
    exit;

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

try {
    $pdo = getDBConnection();
    $adminId = $_SESSION['admin_id'] ?? 1; // Default to 1 if session missing, though usually restricted

    // Read JSON Input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception("Input JSON tidak valid");
    }

    // Validate Required Fields
    if (empty($input['laporan_id']))
        throw new Exception("ID Laporan wajib diisi");
    if (empty($input['psikolog_id']))
        throw new Exception("Pilih Psikolog dengan benar");
    if (empty($input['waktu_mulai']))
        throw new Exception("Waktu mulai wajib diisi");
    if (empty($input['waktu_selesai']))
        throw new Exception("Waktu selesai wajib diisi");
    if (empty($input['tipe']))
        throw new Exception("Tipe pertemuan wajib dipilih");
    if (empty($input['tempat']))
        throw new Exception("Tempat atau link pertemuan wajib diisi");

    $laporanId = (int)$input['laporan_id'];
    $psikologId = (int)$input['psikolog_id'];
    $waktuMulai = $input['waktu_mulai'];
    $waktuSelesai = $input['waktu_selesai'];
    $tipe = $input['tipe']; // 'online' or 'offline'
    $tempat = trim($input['tempat']);

    // Fetch Details for Email/Log
    $stmtLaporan = $pdo->prepare("SELECT kode_pelaporan, email_korban, status_laporan FROM Laporan WHERE id = ? LIMIT 1");
    $stmtLaporan->execute([$laporanId]);
    $laporan = $stmtLaporan->fetch(PDO::FETCH_ASSOC);

    if (!$laporan)
        throw new Exception("Laporan tidak ditemukan");

    $stmtPsikolog = $pdo->prepare("SELECT nama_lengkap FROM Psikolog WHERE id = ? LIMIT 1");
    $stmtPsikolog->execute([$psikologId]);
    $psikolog = $stmtPsikolog->fetch(PDO::FETCH_ASSOC);
    $psikologName = $psikolog['nama_lengkap'] ?? 'Psikolog';

    // Transaction
    $pdo->beginTransaction();

    // 1. Insert JadwalPertemuan
    $stmt = $pdo->prepare("
        INSERT INTO JadwalPertemuan (
            laporan_id, psikolog_id, scheduled_by_admin, waktu_mulai, waktu_selesai, 
            tipe, tempat_atau_link, status_jadwal, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 'scheduled', NOW()
        )
    ");

    $stmt->execute([
        $laporanId, $psikologId, $adminId, $waktuMulai, $waktuSelesai,
        $tipe, $tempat
    ]);

    $jadwalId = $pdo->lastInsertId();

    // 2. Update Laporan Status & Assign Psikolog
    $oldStatus = $laporan['status_laporan'];
    $stmtUpdate = $pdo->prepare("
        UPDATE Laporan 
        SET 
            status_laporan = 'Dijadwalkan',
            assigned_psikolog_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ");

    $stmtUpdate->execute([$psikologId, $laporanId]);

    // 3. Log Status History
    $stmtLog = $pdo->prepare("
        INSERT INTO StatusHistory (
            laporan_id, status_lama, status_baru, 
            diubah_oleh_role, diubah_oleh_id, keterangan, created_at
        ) VALUES (
            ?, ?, 'Dijadwalkan', 'admin', ?, ?, NOW()
        )
    ");
    $keterangan = "Jadwal pertemuan dibuat. Psikolog: $psikologName. Tipe: $tipe";
    $stmtLog->execute([
        $laporanId, $oldStatus, $adminId, $keterangan
    ]);

    $pdo->commit();

    // 4. Send Email Notification (Gratis via Gmail SMTP)
    try {
        if (!empty($laporan['email_korban'])) {
            $emailSubject = "[SIGAP PPKPT] Jadwal Konsultasi: " . $laporan['kode_pelaporan'];
            $emailBody = "
                <div style='font-family: Arial, sans-serif; color: #333;'>
                    <h2 style='color: #0d9488;'>Jadwal Konsultasi Telah Dibuat</h2>
                    <p>Halo,</p>
                    <p>Admin telah menjadwalkan sesi konsultasi untuk laporan <strong>" . $laporan['kode_pelaporan'] . "</strong>.</p>
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 5px 0;'><strong>Psikolog:</strong> " . htmlspecialchars($psikologName) . "</p>
                        <p style='margin: 5px 0;'><strong>Waktu Mulai:</strong> " . date('d M Y, H:i', strtotime($waktuMulai)) . "</p>
                        <p style='margin: 5px 0;'><strong>Waktu Selesai:</strong> " . date('H:i', strtotime($waktuSelesai)) . "</p>
                        <p style='margin: 5px 0;'><strong>Tipe:</strong> " . strtoupper($tipe) . "</p>
                        <p style='margin: 5px 0;'><strong>Tempat/Link:</strong> " . htmlspecialchars($tempat) . "</p>
                    </div>
                    <p>Mohon hadir tepat waktu. Jika berhalangan, silakan hubungi admin.</p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p style='font-size: 0.9em; color: #666;'><i>Ini adalah pesan otomatis.</i></p>
                </div>
            ";

            MailerService::send($laporan['email_korban'], $emailSubject, $emailBody);
        }
    }
    catch (Exception $mailErr) {
        error_log("Email Failed: " . $mailErr->getMessage());
    }

    // 5. Blockchain Logging
    try {
        $payload = json_encode([
            'jadwal_id' => $jadwalId,
            'psikolog' => $psikologName,
            'waktu_mulai' => $waktuMulai,
            'waktu_selesai' => $waktuSelesai,
            'tipe' => $tipe,
            'status' => 'Dijadwalkan'
        ]);
        $hash = hash('sha256', $payload);

        BlockchainService::addLog($laporan['kode_pelaporan'], 'UPDATE_ADMIN', $hash, $payload, 'ADMIN');
    }
    catch (Exception $bcErr) {
        error_log("Blockchain Log Failed: " . $bcErr->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Jadwal berhasil dibuat',
        'jadwal_id' => $jadwalId
    ]);

}
catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
