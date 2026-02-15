<?php
/**
 * SIGAP PPKS - API Update Kasus
 */
// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Only allow POST/PUT
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    exit(json_encode(['status' => 'error', 'message' => 'Method not allowed']));
}

// Disable error display
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/cases_error.log');

// Blockchain Service
require_once __DIR__ . '/../services/BlockchainService.php';
// Mailer Service
require_once __DIR__ . '/../services/MailerService.php';

// ========================================================
// SESSION AUTHENTICATION CHECK
// ========================================================
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443,
    'cookie_samesite' => 'Strict'
]);

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    exit(json_encode([
        'status' => 'error',
        'message' => 'Unauthorized. Please login first.'
    ]));
}

// ========================================================
// GET INPUT DATA (POST or JSON)
// ========================================================

// Try POST first, then JSON
$id = $_POST['id'] ?? '';
$statusLaporan = $_POST['status_laporan'] ?? '';
$csrfToken = $_POST['csrf_token'] ?? '';

// Additional editable fields
$statusDarurat = $_POST['status_darurat'] ?? null;
$pelakuKekerasan = $_POST['pelaku_kekerasan'] ?? null;
$lokasiKejadian = $_POST['lokasi_kejadian'] ?? null;
$detailKejadian = $_POST['detail_kejadian'] ?? null;
$alasanPenolakan = $_POST['alasan_penolakan'] ?? null;

// If POST is empty, try JSON
if (empty($id)) {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        $id = $input['id'] ?? '';
        $statusLaporan = $input['status_laporan'] ?? '';
        $csrfToken = $input['csrf_token'] ?? '';
        $statusDarurat = $input['status_darurat'] ?? null;
        $pelakuKekerasan = $input['pelaku_kekerasan'] ?? null;
        $lokasiKejadian = $input['lokasi_kejadian'] ?? null;
        $detailKejadian = $input['detail_kejadian'] ?? null;
        $alasanPenolakan = $input['alasan_penolakan'] ?? null;
    }
}

// ========================================================
// CSRF TOKEN VALIDATION
// ========================================================

if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || $csrfToken !== $_SESSION['csrf_token']) {
    error_log("SECURITY: CSRF token mismatch on case update - Admin ID: " . ($_SESSION['admin_id'] ?? 'unknown'));
    http_response_code(403);
    exit(json_encode([
        'status' => 'error',
        'message' => 'Invalid security token. Please refresh the page.'
    ]));
}

// ========================================================
// INPUT VALIDATION
// ========================================================

// Validate case ID
$caseId = filter_var($id, FILTER_VALIDATE_INT);
if ($caseId === false || $caseId <= 0) {
    http_response_code(400);
    exit(json_encode([
        'status' => 'error',
        'message' => 'Invalid case ID'
    ]));
}

// Validate status_laporan (supports both legacy and new 5-phase statuses)
$allowedStatuses = ['Process', 'In Progress', 'Completed', 'Investigasi', 'Ditolak', 'Dilanjutkan', 'Dijadwalkan', 'Konsultasi', 'Menunggu_Konfirmasi', 'Dispute', 'Eskalasi_Admin', 'Closed'];
if (!empty($statusLaporan) && !in_array($statusLaporan, $allowedStatuses)) {
    http_response_code(400);
    exit(json_encode([
        'status' => 'error',
        'message' => 'Invalid status. Allowed: ' . implode(', ', $allowedStatuses)
    ]));
}

// Validate rejection requires reason
if ($statusLaporan === 'Ditolak' && empty($alasanPenolakan)) {
    http_response_code(400);
    exit(json_encode([
        'status' => 'error',
        'message' => 'Alasan penolakan wajib diisi jika status Ditolak'
    ]));
}

// ========================================================
// DATABASE CONNECTION
// ========================================================
require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = getDBConnection();
}
catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode([
        'status' => 'error',
        'message' => 'Database connection failed'
    ]));
}

// ========================================================
// CHECK IF CASE EXISTS
// ========================================================

try {
    $checkQuery = "SELECT * FROM Laporan WHERE id = :id LIMIT 1";
    $checkStmt = $pdo->prepare($checkQuery);
    $checkStmt->execute([':id' => $caseId]);
    $existingCase = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingCase) {
        http_response_code(404);
        exit(json_encode([
            'status' => 'error',
            'message' => 'Case not found'
        ]));
    }

    // ========================================================
    // BUILD UPDATE QUERY & TRACK CHANGES
    // ========================================================

    $updateFields = [];
    $dataChanges = []; // Array to store detailed changes
    $params = [':id' => $caseId];

    // Helper function to track changes
    $trackChange = function ($fieldLabel, $dbField, $newValue, $oldValue) use (&$dataChanges) {
        if ($newValue != $oldValue) {
            $dataChanges[] = [
                'field' => $fieldLabel,
                'old' => $oldValue,
                'new' => $newValue
            ];
            return true;
        }
        return false;
    };

    // Only update fields that are provided
    if (!empty($statusLaporan)) {
        if ($statusLaporan !== $existingCase['status_laporan']) {
            $updateFields[] = "status_laporan = :status_laporan";
            $params[':status_laporan'] = $statusLaporan;
        // Status change is tracked via status_lama/status_baru columns, but we can add to details too if desired
        }
    }

    if ($statusDarurat !== null) {
        $val = trim($statusDarurat);
        if ($trackChange('Status Darurat', 'status_darurat', $val, $existingCase['status_darurat'])) {
            $updateFields[] = "status_darurat = :status_darurat";
            $params[':status_darurat'] = $val;
        }
    }

    if ($pelakuKekerasan !== null) {
        $val = trim($pelakuKekerasan);
        if ($trackChange('Pelaku Kekerasan', 'pelaku_kekerasan', $val, $existingCase['pelaku_kekerasan'])) {
            $updateFields[] = "pelaku_kekerasan = :pelaku_kekerasan";
            $params[':pelaku_kekerasan'] = $val;
        }
    }

    if ($lokasiKejadian !== null) {
        $val = trim($lokasiKejadian);
        if ($trackChange('Lokasi Kejadian', 'lokasi_kejadian', $val, $existingCase['lokasi_kejadian'])) {
            $updateFields[] = "lokasi_kejadian = :lokasi_kejadian";
            $params[':lokasi_kejadian'] = $val;
        }
    }

    if ($detailKejadian !== null) {
        $val = trim($detailKejadian);
        if ($trackChange('Detail Kejadian', 'detail_kejadian', $val, $existingCase['detail_kejadian'])) {
            $updateFields[] = "detail_kejadian = :detail_kejadian";
            $params[':detail_kejadian'] = $val;
        }
    }

    // Handle rejection reason
    if ($alasanPenolakan !== null) {
        $val = trim($alasanPenolakan);
        // Always update if provided
        $updateFields[] = "alasan_penolakan = :alasan_penolakan";
        $params[':alasan_penolakan'] = $val;
    }

    // Set validated_by_admin if status is being changed
    if (!empty($statusLaporan) && in_array($statusLaporan, ['Ditolak', 'Dilanjutkan', 'Investigasi'])) {
        $updateFields[] = "validated_by_admin = :admin_id";
        $params[':admin_id'] = $_SESSION['admin_id'] ?? null;
    }

    // Check if there's anything to update
    if (empty($updateFields)) {
        http_response_code(400);
        exit(json_encode([
            'status' => 'error',
            'message' => 'No changes detected'
        ]));
    }

    // Build and execute update query
    $updateQuery = "UPDATE Laporan SET " . implode(', ', $updateFields) . " WHERE id = :id";
    $updateStmt = $pdo->prepare($updateQuery);
    $updateStmt->execute($params);

    // Log the update for audit
    error_log("CASE UPDATED - ID: $caseId, Admin: " . ($_SESSION['admin_email'] ?? 'unknown'));

    // Log status change or data update to StatusHistory
    $statusChanged = (!empty($statusLaporan) && $statusLaporan !== $existingCase['status_laporan']);
    $hasDataChanges = !empty($dataChanges);

    if ($statusChanged || $hasDataChanges) {
        try {
            // Note: Ensure migration_v3_audit_details.sql is applied
            $historyQuery = "INSERT INTO StatusHistory (laporan_id, status_lama, status_baru, diubah_oleh_role, diubah_oleh_id, keterangan, perubahan_data) VALUES (:lid, :old, :new, 'admin', :aid, :ket, :diff)";
            $historyStmt = $pdo->prepare($historyQuery);

            $statusLama = $existingCase['status_laporan'];
            $statusBaru = $statusChanged ? $statusLaporan : $statusLama;

            if ($statusChanged) {
                $keterangan = $statusLaporan === 'Ditolak' ? 'Ditolak oleh Admin. Alasan: ' . ($alasanPenolakan ?? '-') : 'Status diubah oleh Admin';
            }
            else {
                $keterangan = 'Admin memperbarui data laporan';
            }

            // Encode diff to JSON
            $jsonDiff = !empty($dataChanges) ? json_encode($dataChanges) : null;

            $historyStmt->execute([
                ':lid' => $caseId,
                ':old' => $statusLama,
                ':new' => $statusBaru,
                ':aid' => $_SESSION['admin_id'] ?? null,
                ':ket' => $keterangan,
                ':diff' => $jsonDiff
            ]);
        }
        catch (PDOException $histErr) {
            error_log("StatusHistory insert failed: " . $histErr->getMessage());
        }

        // Blockchain Logging
        try {
            $payload = json_encode([
                'status_lama' => $statusLama,
                'status_baru' => $statusBaru,
                'keterangan' => $keterangan,
                'changes' => $dataChanges
            ]);
            $hash = hash('sha256', $payload);

            // Use existingCase['kode_pelaporan'] if available, otherwise fetch
            $kodeLaporan = $existingCase['kode_pelaporan'] ?? ('ID:' . $caseId);

            BlockchainService::addLog($kodeLaporan, 'UPDATE_ADMIN', $hash, $payload, 'ADMIN');
        }
        catch (Exception $bcErr) {
            error_log("Blockchain Log Failed: " . $bcErr->getMessage());
        }
    }

    // Send Email Notification (Gratis via Gmail SMTP)
    try {
        file_put_contents(__DIR__ . '/../mail_debug.log', date('Y-m-d H:i:s') . " DEBUG UPDATE: Case " . ($caseId ?? 'Unknown') . " - Email: " . ($existingCase['email_korban'] ?? 'NULL') . "\n", FILE_APPEND);
        if (!empty($existingCase['email_korban'])) {
            $statusDisplay = !empty($statusLaporan) ? $statusLaporan : "Diperbarui";
            $emailSubject = "[SIGAP PPKPT] Update Status Laporan #" . $existingCase['kode_pelaporan'];
            $emailBody = "
                <div style='font-family: Arial, sans-serif; color: #333;'>
                    <h2 style='color: #0d9488;'>Update Status Laporan</h2>
                    <p>Halo,</p>
                    <p>Ada pembaruan terkait laporan Anda dengan Kode: <strong>" . $existingCase['kode_pelaporan'] . "</strong></p>
                    <div style='background: #f3f4f6; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 5px 0;'><strong>Status Terbaru:</strong> <span style='font-size: 1.2em; color: #0d9488;'>" . strtoupper($statusDisplay) . "</span></p>
                        " . (!empty($keterangan) ? "<p style='margin: 5px 0;'><strong>Keterangan:</strong> " . htmlspecialchars($keterangan) . "</p>" : "") . "
                    </div>
                    <p>Silakan login atau cek menu <strong>Monitoring</strong> untuk detail lebih lanjut.</p>
                    <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                    <p style='font-size: 0.9em; color: #666;'><i>Ini adalah pesan otomatis. Mohon tidak membalas email ini.</i></p>
                </div>
            ";

            MailerService::send($existingCase['email_korban'], $emailSubject, $emailBody);
        }
    }
    catch (Exception $mailErr) {
        error_log("Email Notification Failed: " . $mailErr->getMessage());
    }

    // Get updated case data
    $getUpdatedQuery = "SELECT * FROM Laporan WHERE id = :id LIMIT 1";
    $getUpdatedStmt = $pdo->prepare($getUpdatedQuery);
    $getUpdatedStmt->execute([':id' => $caseId]);
    $updatedCase = $getUpdatedStmt->fetch(PDO::FETCH_ASSOC);

    // Success response
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Case updated successfully',
        'data' => [
            'id' => (int)$updatedCase['id'],
            'kode_pelaporan' => $updatedCase['kode_pelaporan'],
            'status_laporan' => $updatedCase['status_laporan'],
            'alasan_penolakan' => $updatedCase['alasan_penolakan'] ?? null,
            'updated_at' => $updatedCase['updated_at']
        ],
        'csrf_token' => $_SESSION['csrf_token'] ?? ''
    ]);

}
catch (PDOException $e) {
    error_log("Database query failed: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode([
        'status' => 'error',
        'message' => 'Failed to update case'
    ]));
}

exit;
