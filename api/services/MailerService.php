<?php
require_once __DIR__ . '/../../config/mail.php';

class MailerService
{

    /**
     * Mengirim email menggunakan SMTP (Gratis via Gmail).
     * @param string $to Email tujuan
     * @param string $subject Subjek Email
     * @param string $body Isi Email (HTML)
     * @return bool Berhasil atau Tidak
     */
    public static function send($to, $subject, $body)
    {
        if (!defined('MAIL_ENABLED') || !MAIL_ENABLED) {
            return false;
        }

        $host = MAIL_HOST;
        $port = MAIL_PORT;
        $user = MAIL_USERNAME;
        $pass = str_replace(' ', '', MAIL_PASSWORD); // Remove spaces from App Password
        $fromName = MAIL_FROM_NAME;

        // Buka koneksi socket ke SMTP server
        $socket = fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) {
            error_log("SMTP Error: $errno - $errstr");
            return false;
        }

        // Helper function untuk membaca respon dari server
        $read = function () use ($socket) {
            $msg = '';
            while ($str = fgets($socket, 515)) {
                $msg .= $str;
                if (substr($str, 3, 1) == " ") {
                    break;
                }
            }
            return $msg;
        };

        // Handshake awal
        $read();

        // 1. EHLO
        fputs($socket, "EHLO $host\r\n");
        $read();

        // 2. STARTTLS (Jika port 587)
        if ($port == 587) {
            fputs($socket, "STARTTLS\r\n");
            $read();
            // Aktifkan enkripsi SSL/TLS pada socket
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fputs($socket, "EHLO $host\r\n");
            $read();
        }

        // 3. AUTH LOGIN
        fputs($socket, "AUTH LOGIN\r\n");
        $read();
        fputs($socket, base64_encode($user) . "\r\n");
        $read();
        fputs($socket, base64_encode($pass) . "\r\n");
        $resp = $read();

        if (strpos($resp, '235') === false) { // 235 Authentication successful
            error_log("SMTP Auth Failed: $resp");
            fclose($socket);
            return false;
        }

        // 4. MAIL FROM
        fputs($socket, "MAIL FROM: <$user>\r\n");
        $read();

        // 5. RCPT TO
        fputs($socket, "RCPT TO: <$to>\r\n");
        $read();

        // 6. DATA (Isi Email)
        fputs($socket, "DATA\r\n");
        $read();

        // Headers
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: $fromName <$user>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $headers .= "Date: " . date("r") . "\r\n";

        fputs($socket, "$headers\r\n$body\r\n.\r\n");
        $resp = $read();

        if (strpos($resp, '250') === false) { // 250 OK
            $errMsg = "SMTP Data Failed: $resp";
            error_log($errMsg);
            file_put_contents(__DIR__ . '/../mail_debug.log', date('Y-m-d H:i:s') . " FAIL: $to - $errMsg\n", FILE_APPEND);
            fclose($socket);
            return false;
        }

        // 7. QUIT
        fputs($socket, "QUIT\r\n");
        fclose($socket);

        file_put_contents(__DIR__ . '/../mail_debug.log', date('Y-m-d H:i:s') . " SUCCESS: $to - Subject: $subject\n", FILE_APPEND);
        return true;
    }
}
