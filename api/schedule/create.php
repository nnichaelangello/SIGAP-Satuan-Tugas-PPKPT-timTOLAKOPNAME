<?php
/**
 * SIGAP PPKS - Create Schedule (Admin only)
 * POST: { laporan_id, psikolog_id, waktu_mulai, waktu_selesai, tipe, tempat_atau_link, catatan_admin, csrf_token }
 */
header('Content-Type: application/json; charset=utf-8');
file_put_contents(__DIR__ . '/../request_debug.log', date('Y-m-d H:i:s') . " HIT CREATE.PHP Input: " . file_get_contents('php://input') . "\n", FILE_APPEND);
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/schedule_error.log');

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    exit(json_encode(['status' => 'error', 'message' => 'Unauthorized. Admin login required.']));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input)
    $input = $_POST;

// CSRF
if (empty($input['csrf_token']) || ($input['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['status' => 'error', 'message' => 'Invalid security token']));
}

$laporanId = filter_var($input['laporan_id'] ?? '', FILTER_VALIDATE_INT);
$psikologId = filter_var($input['psikolog_id'] ?? '', FILTER_VALIDATE_INT);
$waktuMulai = trim($input['waktu_mulai'] ?? '');
$waktuSelesai = trim($input['waktu_selesai'] ?? '');
$tipe = $input['tipe'] ?? 'offline';
$tempatAtauLink = trim($input['tempat_atau_link'] ?? '');
$catatanAdmin = trim($input['catatan_admin'] ?? '');

if (!$laporanId || !$psikologId || empty($waktuMulai) || empty($waktuSelesai) || empty($tempatAtauLink)) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Semua field wajib diisi (laporan_id, psikolog_id, waktu_mulai, waktu_selesai, tempat/link)']));
}

if (!in_array($tipe, ['online', 'offline'])) {
    $tipe = 'offline';
}

// Validate datetime
$mulai = strtotime($waktuMulai);
$selesai = strtotime($waktuSelesai);
if (!$mulai || !$selesai || $selesai <= $mulai) {
    http_response_code(400);
    exit(json_encode(['status' => 'error', 'message' => 'Waktu mulai harus sebelum waktu selesai']));
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../services/MailerService.php';

try {
    $pdo = getDBConnection();
    $adminId = $_SESSION['admin_id'] ?? null;

    // Verify laporan exists and is in correct status
    $checkLaporan = $pdo->prepare("SELECT id, status_laporan, kode_pelaporan, email_korban FROM Laporan WHERE id = :id LIMIT 1");
    $checkLaporan->execute([':id' => $laporanId]);
    $laporan = $checkLaporan->fetch(PDO::FETCH_ASSOC);

    if (!$laporan) {
        http_response_code(404);
        exit(json_encode(['status' => 'error', 'message' => 'Laporan tidak ditemukan']));
    }

    if (!in_array($laporan['status_laporan'], ['Dilanjutkan', 'Dijadwalkan'])) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Laporan harus berstatus Dilanjutkan untuk dijadwalkan']));
    }

    // Verify psikolog exists and is active
    $checkPsikolog = $pdo->prepare("SELECT id, nama_lengkap FROM Psikolog WHERE id = :id AND status = 'aktif' LIMIT 1");
    $checkPsikolog->execute([':id' => $psikologId]);
    $psikolog = $checkPsikolog->fetch(PDO::FETCH_ASSOC);

    if (!$psikolog) {
        http_response_code(404);
        exit(json_encode(['status' => 'error', 'message' => 'Psikolog tidak ditemukan atau tidak aktif']));
    }

    $pdo->beginTransaction();

    // Insert schedule
    $insertQuery = "INSERT INTO JadwalPertemuan 
        (laporan_id, psikolog_id, scheduled_by_admin, waktu_mulai, waktu_selesai, tipe, tempat_atau_link, catatan_admin) 
        VALUES (:lid, :pid, :aid, :mulai, :selesai, :tipe, :tempat, :catatan)";
    $insertStmt = $pdo->prepare($insertQuery);
    $insertStmt->execute([
        ':lid' => $laporanId,
        ':pid' => $psikologId,
        ':aid' => $adminId,
        ':mulai' => $waktuMulai,
        ':selesai' => $waktuSelesai,
        ':tipe' => $tipe,
        ':tempat' => $tempatAtauLink,
        ':catatan' => $catatanAdmin ?: null
    ]);
    $scheduleId = $pdo->lastInsertId();

    // Update laporan status and assign psikolog
    $oldStatus = $laporan['status_laporan'];
    $pdo->prepare("UPDATE Laporan SET status_laporan = 'Dijadwalkan', assigned_psikolog_id = :pid WHERE id = :lid")
        ->execute([':pid' => $psikologId, ':lid' => $laporanId]);

    // Log status change
    $pdo->prepare("INSERT INTO StatusHistory (laporan_id, status_lama, status_baru, diubah_oleh_role, diubah_oleh_id, keterangan) VALUES (:lid, :old, 'Dijadwalkan', 'admin', :aid, :ket)")
        ->execute([
        ':lid' => $laporanId,
        ':old' => $oldStatus,
        ':aid' => $adminId,
        ':ket' => "Jadwal pertemuan dibuat. Psikolog: {$psikolog['nama_lengkap']}. Tipe: $tipe"
    ]);

    $pdo->commit();

    // Send Email Notification to User (Gratis via Gmail SMTP)
    try {
        file_put_contents(__DIR__ . '/../mail_debug.log', date('Y-m-d H:i:s') . " DEBUG CREATE: ID $laporanId - Email: " . ($laporan['email_korban'] ?? 'NULL') . "\n", FILE_APPEND);
        if (!empty($laporan['email_korban'])) {
            $emailSubject = "[SIGAP PPKPT] Jadwal Konsultasi: " . $laporan['kode_pelaporan'];
            $emailBody = "
                <div style='font-family: Arial, sans-serif; color: #333;'>
                    <h2 style='color: #0d9488;'>Jadwal Konsultasi Telah Dibuat</h2>
                    <p>Halo,</p>
                    <p>Admin telah menjadwalkan sesi konsultasi untuk laporan <strong>" . $laporan['kode_pelaporan'] . "</strong>.</p>
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 5px 0;'><strong>Psikolog:</strong> " . $psikolog['nama_lengkap'] . "</p>
                        <p style='margin: 5px 0;'><strong>Waktu Mulai:</strong> " . date('d M Y, H:i', strtotime($waktuMulai)) . "</p>
                        <p style='margin: 5px 0;'><strong>Waktu Selesai:</strong> " . date('H:i', strtotime($waktuSelesai)) . "</p>
                        <p style='margin: 5px 0;'><strong>Tipe:</strong> " . strtoupper($tipe) . "</p>
                        <p style='margin: 5px 0;'><strong>Tempat/Link:</strong> " . htmlspecialchars($tempatAtauLink) . "</p>
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
        error_log("Email Notification Failed: " . $mailErr->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Jadwal pertemuan berhasil dibuat',
        'data' => [
            'jadwal_id' => (int)$scheduleId,
            'laporan_kode' => $laporan['kode_pelaporan'],
            'psikolog_nama' => $psikolog['nama_lengkap'],
            'waktu_mulai' => $waktuMulai,
            'waktu_selesai' => $waktuSelesai,
            'tipe' => $tipe,
            'tempat_atau_link' => $tempatAtauLink
        ],
        'csrf_token' => $_SESSION['csrf_token'] ?? ''
    ]);

}
catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    error_log("Create schedule error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal membuat jadwal']);
}
exit;
