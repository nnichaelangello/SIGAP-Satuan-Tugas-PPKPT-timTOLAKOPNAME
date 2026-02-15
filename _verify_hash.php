<?php
$password = 'psikolog123';
$hash = '$2y$10$74bAl4xDgGxXOwboWNY9euo8mK99dMcTUJp0CqkYLRX2ZjEcwT9o';

if (password_verify($password, $hash)) {
    echo "BENAR! Password '$password' COCOK dengan hash.\n";
}
else {
    echo "SALAH! Password tidak cocok.\n";
}
