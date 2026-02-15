<?php
/**
 * SIGAP PPKS - API Cari Laporan
 */
// Start output buffering
ob_start();

// Load CORS helper
require_once __DIR__ . '/../../config/cors.php';

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Headers & CORS
header('Content-Type: application/json; charset=utf-8');
if (handlePublicCors()) {
    exit; // Preflight request handled
}

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, 'Method not allowed. Use GET.', null, 405);
}

// Include database config
require_once __DIR__ . '/../../config/database.php';

// Validate PDO connection
if (!isset($pdo) || !($pdo instanceof PDO)) {
    sendResponse(false, 'Database connection failed', null, 500);
}

try {
    // ==========================================
    // DUAL SEARCH: Accept 'query' or 'kode'
    // ==========================================
    $query = isset($_GET['query']) ? trim($_GET['query']) :
        (isset($_GET['kode']) ? trim($_GET['kode']) : null);

    if (empty($query)) {
        sendResponse(false, 'Parameter query (kode atau email) wajib diisi', [
            'examples' => [
                'By kode: ?query=PPKPT228236148',
                'By email: ?query=test@student.itb.ac.id'
            ]
        ], 400);
    }

    // ==========================================
    // AUTO-DETECT: Email or Kode?
    // ==========================================
    $query = trim($query);
    $isEmail = filter_var($query, FILTER_VALIDATE_EMAIL);

    // Log search attempt
    error_log("Search Request - Type: " . ($isEmail ? 'EMAIL' : 'KODE') . ", Query: $query");

    // ==========================================
    // BUILD DYNAMIC SQL
    // ==========================================
    $sql = "SELECT 
                l.id,
                l.kode_pelaporan,
                l.status_laporan,
                l.status_darurat,
                l.korban_sebagai,
                l.tingkat_kekhawatiran,
                l.gender_korban,
                l.pelaku_kekerasan,
                l.waktu_kejadian,
                l.lokasi_kejadian,
                l.detail_kejadian,
                l.email_korban,
                l.usia_korban,
                l.whatsapp_korban,
                l.status_disabilitas,
                l.jenis_disabilitas,
                l.alasan_penolakan,
                l.dispute_count,
                l.auto_close_at,
                l.created_at,
                l.updated_at,
                j.id AS jadwal_id,
                j.waktu_mulai,
                j.waktu_selesai,
                j.tempat_atau_link,
                j.tipe AS tipe_pertemuan,
                j.status_jadwal,
                p.nama_lengkap AS psikolog_nama,
                p.spesialisasi AS psikolog_spesialisasi
            FROM Laporan l
            LEFT JOIN JadwalPertemuan j ON l.id = j.laporan_id AND j.status_jadwal != 'cancelled'
            LEFT JOIN Psikolog p ON l.assigned_psikolog_id = p.id
            WHERE ";

    if ($isEmail) {
        // Search by EMAIL
        $sql .= "l.email_korban = ? ORDER BY j.waktu_mulai DESC LIMIT 1";
        $params = [$query];
        $searchType = 'email';
    }
    else {
        // Search by KODE (case-insensitive)
        $query = strtoupper($query); // Normalize kode
        $sql .= "l.kode_pelaporan = ? ORDER BY j.waktu_mulai DESC LIMIT 1";
        $params = [$query];
        $searchType = 'kode';
    }

    // Execute query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $laporan = $stmt->fetch(PDO::FETCH_ASSOC);

    // ==========================================
    // HANDLE NOT FOUND
    // ==========================================
    if (!$laporan) {
        error_log("Not found - $searchType: $query");

        $errorDetail = $isEmail ? [
            'email' => $query,
            'hint' => 'Pastikan email yang digunakan sama dengan saat melapor',
            'search_type' => 'email'
        ] : [
            'kode' => $query,
            'hint' => 'Periksa kembali kode pelaporan Anda (format: PPKPT123456789)',
            'search_type' => 'kode'
        ];

        sendResponse(false, 'Laporan tidak ditemukan', $errorDetail, 404);
    }

    error_log("Found - $searchType: $query, Kode: " . $laporan['kode_pelaporan']);

    // ==========================================
    // GET BUKTI FILES
    // ==========================================
    $sqlBukti = "SELECT 
                    id,
                    file_url,
                    file_type,
                    created_at
                 FROM Bukti 
                 WHERE laporan_id = :laporan_id 
                 ORDER BY created_at ASC";

    $stmtBukti = $pdo->prepare($sqlBukti);
    $stmtBukti->execute(['laporan_id' => $laporan['id']]);
    $buktiFiles = $stmtBukti->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // GET CONSULTATION NOTES (non-draft only)
    // ==========================================
    $sqlNotes = "SELECT 
                    c.id, c.ringkasan_kasus, c.detail_konsultasi, c.rekomendasi, c.tingkat_risiko, 
                    c.status_catatan, c.created_at AS notes_date
                 FROM CatatanKonsultasi c
                 WHERE c.laporan_id = :lid AND c.status_catatan != 'draft'
                 ORDER BY c.created_at DESC LIMIT 1";
    $stmtNotes = $pdo->prepare($sqlNotes);
    $stmtNotes->execute(['lid' => $laporan['id']]);
    $latestNote = $stmtNotes->fetch(PDO::FETCH_ASSOC);

    // ==========================================
    // GET FEEDBACK HISTORY
    // ==========================================
    $sqlFeedback = "SELECT 
                        f.tipe_feedback, f.komentar_user, f.detail_dispute,
                        f.respon_psikolog, f.created_at AS feedback_date
                    FROM FeedbackUser f
                    WHERE f.laporan_id = :lid
                    ORDER BY f.created_at DESC";
    $stmtFeedback = $pdo->prepare($sqlFeedback);
    $stmtFeedback->execute(['lid' => $laporan['id']]);
    $feedbackList = $stmtFeedback->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // GENERATE TIMELINE
    // ==========================================
    $timeline = generateTimeline($laporan, $latestNote, $feedbackList);

    // ==========================================
    // GET STATUS HISTORY (AUDIT TRAIL)
    // ==========================================
    $sqlHistory = "SELECT 
                        sh.status_lama, sh.status_baru, sh.diubah_oleh_role, 
                        sh.keterangan, sh.created_at, sh.perubahan_data
                   FROM StatusHistory sh
                   WHERE sh.laporan_id = :lid
                   ORDER BY sh.created_at DESC";
    $stmtHistory = $pdo->prepare($sqlHistory);
    $stmtHistory->execute(['lid' => $laporan['id']]);
    $historyLog = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // FORMAT RESPONSE
    // ==========================================
    $response = [
        'id' => $laporan['kode_pelaporan'],
        'status' => determineOverallStatus($laporan['status_laporan']),
        'status_raw' => $laporan['status_laporan'],
        'reporterName' => 'Anonymous', // Privacy
        'createdAt' => $laporan['created_at'],
        'updatedAt' => $laporan['updated_at'],
        'searchedBy' => $searchType,
        'steps' => $timeline,
        'history' => $historyLog, // Audit Trail
        'details' => [
            'status_darurat' => $laporan['status_darurat'],
            'korban_sebagai' => $laporan['korban_sebagai'],
            'tingkat_kekhawatiran' => $laporan['tingkat_kekhawatiran'],
            'gender_korban' => $laporan['gender_korban'],
            'pelaku_kekerasan' => $laporan['pelaku_kekerasan'],
            'waktu_kejadian' => $laporan['waktu_kejadian'],
            'lokasi_kejadian' => $laporan['lokasi_kejadian'],
            'detail_kejadian' => $laporan['detail_kejadian'],
            'email_korban' => $laporan['email_korban'],
            'usia_korban' => $laporan['usia_korban'],
            'whatsapp_korban' => $laporan['whatsapp_korban'],
            'status_disabilitas' => $laporan['status_disabilitas'],
            'jenis_disabilitas' => $laporan['jenis_disabilitas'],
            'alasan_penolakan' => $laporan['alasan_penolakan'],
            'dispute_count' => (int)($laporan['dispute_count'] ?? 0),
            'auto_close_at' => $laporan['auto_close_at']
        ],
        'schedule' => $laporan['jadwal_id'] ? [
            'waktu_mulai' => $laporan['waktu_mulai'],
            'waktu_selesai' => $laporan['waktu_selesai'],
            'tempat' => $laporan['tempat_atau_link'],
            'tipe' => $laporan['tipe_pertemuan'],
            'status' => $laporan['status_jadwal'],
            'psikolog' => $laporan['psikolog_nama'],
            'spesialisasi' => $laporan['psikolog_spesialisasi']
        ] : null,
        'consultation' => $latestNote ?: null,
        'feedback' => $feedbackList,
        'bukti' => $buktiFiles
    ];

    // Send success response
    sendResponse(true, 'Laporan ditemukan', $response, 200);


}
catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    sendResponse(false, 'Database error', ['detail' => $e->getMessage()], 500);


}
catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    sendResponse(false, 'Server error', ['detail' => $e->getMessage()], 500);
}

/**
 * Generate timeline steps based on laporan status (5-Phase workflow)
 */
function generateTimeline($laporan, $latestNote = null, $feedbackList = [])
{
    $status = $laporan['status_laporan'];
    $createdAt = $laporan['created_at'];
    $completedStatuses = ['Closed', 'Completed'];
    $advancedStatuses = ['Dilanjutkan', 'Dijadwalkan', 'Konsultasi', 'Menunggu_Konfirmasi', 'Dispute', 'Eskalasi_Admin', 'Closed', 'Completed'];
    $scheduledAndBeyond = ['Dijadwalkan', 'Konsultasi', 'Menunggu_Konfirmasi', 'Dispute', 'Eskalasi_Admin', 'Closed', 'Completed'];
    $consultationAndBeyond = ['Konsultasi', 'Menunggu_Konfirmasi', 'Dispute', 'Eskalasi_Admin', 'Closed', 'Completed'];

    $timeline = [];

    // ===== Phase 1: Laporan Diterima (ALWAYS completed) =====
    $timeline[] = [
        'id' => 1,
        'title' => 'Laporan Diterima',
        'description' => 'Laporan Anda telah berhasil diterima oleh sistem Satgas PPKPT.',
        'status' => 'success',
        'date' => $createdAt,
        'icon' => '✓',
        'phase' => 'submission'
    ];

    // ===== Phase 2: Verifikasi Admin =====
    if ($status === 'Process' || $status === 'Investigasi') {
        $timeline[] = [
            'id' => 2,
            'title' => 'Verifikasi Admin',
            'description' => 'Tim Admin sedang memverifikasi kelengkapan data dan bukti Anda.',
            'status' => 'loading',
            'date' => null,
            'icon' => '⏳',
            'phase' => 'verification'
        ];
    }
    elseif ($status === 'Ditolak') {
        $alasan = $laporan['alasan_penolakan'] ?? 'Tidak memenuhi kriteria';
        $timeline[] = [
            'id' => 2,
            'title' => 'Laporan Ditolak',
            'description' => "Laporan Anda ditolak oleh Admin. Alasan: {$alasan}",
            'status' => 'failed',
            'date' => $laporan['updated_at'],
            'icon' => '✗',
            'phase' => 'verification'
        ];
    }
    else {
        $timeline[] = [
            'id' => 2,
            'title' => 'Verifikasi Selesai',
            'description' => 'Data laporan telah diverifikasi dan dilanjutkan ke tahap berikutnya.',
            'status' => 'success',
            'date' => null,
            'icon' => '✓',
            'phase' => 'verification'
        ];
    }

    // ===== Phase 3: Penjadwalan Konsultasi =====
    if ($status === 'Ditolak') {
        // Skip remaining phases for rejected reports
        return $timeline;
    }

    if ($status === 'Dilanjutkan') {
        $timeline[] = [
            'id' => 3,
            'title' => 'Penjadwalan Konsultasi',
            'description' => 'Menunggu Admin menjadwalkan pertemuan dengan Psikolog.',
            'status' => 'loading',
            'date' => null,
            'icon' => '⏳',
            'phase' => 'scheduling'
        ];
    }
    elseif (in_array($status, $scheduledAndBeyond)) {
        $scheduleInfo = '';
        if ($laporan['waktu_mulai']) {
            $dt = date('d M Y, H:i', strtotime($laporan['waktu_mulai']));
            $tipe = ucfirst($laporan['tipe_pertemuan'] ?? '');
            $scheduleInfo = " Jadwal: {$dt} ({$tipe})";
        }
        $timeline[] = [
            'id' => 3,
            'title' => 'Jadwal Konsultasi',
            'description' => 'Pertemuan dengan Psikolog telah dijadwalkan.' . $scheduleInfo,
            'status' => $status === 'Dijadwalkan' ? 'loading' : 'success',
            'date' => $laporan['waktu_mulai'],
            'icon' => $status === 'Dijadwalkan' ? '📅' : '✓',
            'phase' => 'scheduling'
        ];
    }
    else {
        $timeline[] = [
            'id' => 3,
            'title' => 'Penjadwalan',
            'description' => 'Menunggu tahap penjadwalan konsultasi.',
            'status' => 'pending',
            'date' => null,
            'icon' => '⏸',
            'phase' => 'scheduling'
        ];
    }

    // ===== Phase 4: Sesi dan Feedback =====
    if (in_array($status, $consultationAndBeyond)) {
        if ($status === 'Konsultasi') {
            $timeline[] = [
                'id' => 4,
                'title' => 'Sesi Konsultasi',
                'description' => 'Psikolog sedang menyusun catatan konsultasi untuk Anda.',
                'status' => 'loading',
                'date' => null,
                'icon' => '💬',
                'phase' => 'session'
            ];
        }
        elseif ($status === 'Menunggu_Konfirmasi') {
            $riskInfo = $latestNote ? ' Tingkat risiko: ' . ucfirst($latestNote['tingkat_risiko'] ?? '-') : '';
            $autoClose = $laporan['auto_close_at'] ? ' (auto-close: ' . date('d M Y', strtotime($laporan['auto_close_at'])) . ')' : '';
            $timeline[] = [
                'id' => 4,
                'title' => 'Menunggu Konfirmasi Anda',
                'description' => 'Psikolog telah mengirimkan catatan konsultasi. Silakan tinjau dan konfirmasi atau ajukan keberatan.' . $riskInfo . $autoClose,
                'status' => 'loading',
                'date' => $latestNote['notes_date'] ?? null,
                'icon' => '📝',
                'phase' => 'session',
                'action_required' => true
            ];
        }
        elseif ($status === 'Dispute') {
            $disputeCount = (int)($laporan['dispute_count'] ?? 0);
            $timeline[] = [
                'id' => 4,
                'title' => 'Dispute (Keberatan)',
                'description' => "Anda mengajukan keberatan (dispute ke-{$disputeCount}/3). Psikolog akan meninjau ulang catatan.",
                'status' => 'loading',
                'date' => null,
                'icon' => '⚠️',
                'phase' => 'session'
            ];
        }
        elseif ($status === 'Eskalasi_Admin') {
            $timeline[] = [
                'id' => 4,
                'title' => 'Eskalasi ke Admin',
                'description' => 'Dispute telah mencapai batas maksimum. Kasus dieskalasi ke Admin untuk mediasi.',
                'status' => 'loading',
                'date' => null,
                'icon' => '🔺',
                'phase' => 'session'
            ];
        }
        else {
            $timeline[] = [
                'id' => 4,
                'title' => 'Sesi & Feedback Selesai',
                'description' => 'Catatan konsultasi telah dikonfirmasi.',
                'status' => 'success',
                'date' => null,
                'icon' => '✓',
                'phase' => 'session'
            ];
        }
    }
    else {
        $timeline[] = [
            'id' => 4,
            'title' => 'Sesi & Feedback',
            'description' => 'Menunggu sesi konsultasi dilaksanakan.',
            'status' => 'pending',
            'date' => null,
            'icon' => '⏸',
            'phase' => 'session'
        ];
    }

    // ===== Phase 5: Penutupan Kasus =====
    if (in_array($status, $completedStatuses)) {
        $timeline[] = [
            'id' => 5,
            'title' => 'Kasus Ditutup',
            'description' => 'Laporan Anda telah selesai ditangani. Terima kasih atas kepercayaan Anda kepada Satgas PPKPT.',
            'status' => 'success',
            'date' => $laporan['updated_at'],
            'icon' => '✓',
            'phase' => 'closing'
        ];
    }
    else {
        $timeline[] = [
            'id' => 5,
            'title' => 'Penutupan Kasus',
            'description' => 'Kasus akan ditutup setelah semua tahap selesai.',
            'status' => 'pending',
            'date' => null,
            'icon' => '⏸',
            'phase' => 'closing'
        ];
    }

    return $timeline;
}

/**
 * Determine overall status for frontend
 */
function determineOverallStatus($status_laporan)
{
    switch ($status_laporan) {
        case 'Closed':
        case 'Completed':
            return 'completed';
        case 'Ditolak':
            return 'rejected';
        case 'Investigasi':
        case 'Process':
            return 'in_progress';
        case 'Dilanjutkan':
        case 'Dijadwalkan':
        case 'Konsultasi':
        case 'Menunggu_Konfirmasi':
            return 'in_progress';
        case 'Dispute':
        case 'Eskalasi_Admin':
            return 'dispute';
        default:
            return 'in_progress';
    }
}

/**
 * Send JSON response and exit
 */
function sendResponse($success, $message, $data = null, $statusCode = 200)
{
    ob_clean();
    http_response_code($statusCode);

    $response = [
        'success' => $success,
        'message' => $message
    ];

    if ($data !== null) {
        if ($success) {
            $response['data'] = $data;
        }
        else {
            $response['errors'] = $data;
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}


ob_end_flush();
?>