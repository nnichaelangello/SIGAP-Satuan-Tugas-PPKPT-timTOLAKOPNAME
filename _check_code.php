<?php
require_once 'config/database.php';
$pdo = getDBConnection();
$code = 'PPKPT052545673';

echo "Mencari kode: $code\n";

$stmt = $pdo->prepare("SELECT * FROM Laporan WHERE kode_pelaporan = ?");
$stmt->execute([$code]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if ($data) {
    echo "✅ DITEMUKAN!\n";
    echo "ID: " . $data['id'] . "\n";
    echo "Status: " . $data['status_laporan'] . "\n";
    echo "Email Pelapor: " . $data['email_pelapor'] . "\n";
}
else {
    echo "❌ TIDAK DITEMUKAN di database.\n";

    // Cek apakah ada yang mirip
    echo "Mencari yang mirip...\n";
    $stmt = $pdo->query("SELECT kode_pelaporan FROM Laporan LIMIT 5");
    while ($row = $stmt->fetch()) {
        echo "- " . $row['kode_pelaporan'] . "\n";
    }
}
