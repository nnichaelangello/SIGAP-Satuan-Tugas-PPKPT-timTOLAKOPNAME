<?php
// Konfigurasi Email (Gratis via Gmail SMTP)
// Untuk menggunakan Gmail, Anda BUTUH "App Password":
// 1. Login ke akun Google Anda.
// 2. Aktifkan 2-Step Verification.
// 3. Masuk ke App Passwords (https://myaccount.google.com/apppasswords).
// 4. Generate App Password baru untuk "Mail".
// 5. Masukkan password 16 karakter tersebut di bawah (MAIL_PASSWORD).

define('MAIL_ENABLED', true); // Set false jika ingin mematikan fitur email sementara
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587); // Gunakan 587 untuk TLS (standar)
define('MAIL_USERNAME', 'michaelriyadi02@gmail.com'); // Ganti dengan Email Gmail Anda
define('MAIL_PASSWORD', 'lfgy havy tthc dhoh'); // Ganti dengan App Password Gmail Anda (bukan password login biasa)
define('MAIL_FROM_NAME', 'Sistem Pelaporan SIGAP PPKPT');
