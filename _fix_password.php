<?php
require_once 'config/database.php';

$password = 'psikolog123';
$new_hash = password_hash($password, PASSWORD_DEFAULT);
$email = 'psikolog123@gmail.com';

echo "Password: $password\n";
echo "Hash Baru: $new_hash\n";

try {
    $pdo = getDBConnection();

    // Cek dulu apakah user ada
    $stmt = $pdo->prepare("SELECT id FROM Psikolog WHERE email = ?");
    $stmt->execute([$email]);

    if ($stmt->rowCount() > 0) {
        // Update hash jika user ada
        $sql = "UPDATE Psikolog SET password_hash = ? WHERE email = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_hash, $email]);
        echo "✅ BERHASIL: Password hash untuk '$email' telah diperbarui di database.\n";
        echo "Silakan login sekarang.\n";
    }
    else {
        echo "⚠️ WARNING: User dengan email '$email' TIDAK DITEMUKAN.\n";
        echo "Masukkan user baru? (Jalankan query INSERT manual)\n";
    }
}
catch (PDOException $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
