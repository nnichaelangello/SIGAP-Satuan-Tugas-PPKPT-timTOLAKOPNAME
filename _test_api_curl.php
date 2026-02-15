<?php
// Simulate HTTP Request
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost';

// Fake file_get_contents('php://input')
$data = json_encode(['query' => 'PPKPT052545673']);

// We need to buffer output because check_progress.php uses ob_start
// and we want to capture it
// But 'php://input' is readonly. We can't overwrite it easily for CLI.

// Instead, I'll modify check_progress.php temporarily to accept CLI argument or hardcoded value for testing?
// No, that changes the code.

// Let's use curl to hit the actual endpoint if server is running?
// The user says "Not Found" on some URLs, implying server is running.
// URL: http://localhost/sigap/api/monitoring/check_progress.php

$ch = curl_init('http://localhost/sigap/api/monitoring/check_progress.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
