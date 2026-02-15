<?php
$url = 'http://localhost/sigap/api/monitoring/get_laporan.php?kode=PPKPT052545673';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// curl_setopt($ch, CURLOPT_VERBOSE, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($response === false) {
    echo "CURL Error: " . curl_error($ch) . "\n";
}

curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
