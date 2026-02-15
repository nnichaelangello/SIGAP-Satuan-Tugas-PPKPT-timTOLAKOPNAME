<?php
/**
 * SIGAP PPKS - Get Consultation Notes
 * GET: ?laporan_id=X or ?kode_pelaporan=X&email=X or ?psikolog_id=X
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

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict'
]);

$isPsikolog = isset($_SESSION['psikolog_logged_in']) && $_SESSION['psikolog_logged_in'] === true;
$psikologId = $_SESSION['psikolog_id'] ?? 0;

try {
    $pdo = getDBConnection();

    $laporanId = filter_var($_GET['laporan_id'] ?? '', FILTER_VALIDATE_INT);
    $kode = trim($_GET['kode_pelaporan'] ?? '');
    $email = trim($_GET['email'] ?? '');

    if (!$laporanId && (empty($kode) || empty($email)) && !$isPsikolog) {
    // Jika psikolog login, mungkin dia request by laporan_id tanpa kode/email.
    // Tapi validasi original membutuhkan minimal satu identitas.
    }

    if (!$laporanId && (empty($kode) || empty($email))) {
        http_response_code(400);
        exit(json_encode(['status' => 'error', 'message' => 'Identitas laporan diperlukan']));
    }

    // Resolve by kode + email
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

    $query = "SELECT 
        ck.*,
        p.nama_lengkap as psikolog_nama,
        p.spesialisasi as psikolog_spesialisasi,
        p.foto_url as psikolog_foto,
        j.waktu_mulai as jadwal_waktu,
        j.tipe as jadwal_tipe,
        j.tempat_atau_link as jadwal_tempat
    FROM CatatanKonsultasi ck
    JOIN Psikolog p ON p.id = ck.psikolog_id
    LEFT JOIN JadwalPertemuan j ON j.id = ck.jadwal_id
    WHERE ck.laporan_id = :lid";

    $params = [':lid' => $laporanId];

    // Filter Logic:
    // User (Not Psikolog) -> Cannot see Draft
    // Psikolog -> Can see Draft ONLY if it belongs to them

    if ($isPsikolog) {
        $query .= " AND (ck.status_catatan != 'draft' OR ck.psikolog_id = :pid)";
        $params[':pid'] = $psikologId;
    }
    else {
        $query .= " AND ck.status_catatan != 'draft'";
    }

    $query .= " ORDER BY ck.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $notes,
        'total' => count($notes)
    ]);

}
catch (Exception $e) {
    error_log("Get consultation notes error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal mengambil catatan konsultasi']);
}
exit;
