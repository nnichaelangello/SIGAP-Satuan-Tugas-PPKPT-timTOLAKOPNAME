<?php
require_once __DIR__ . '/config/database.php';
$pdo = getDBConnection();
$r = $pdo->query('DESCRIBE Psikolog');
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . ' | ' . $row['Type'] . ' | ' . $row['Null'] . ' | ' . $row['Key'] . "\n";
}
