
<?php
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env');
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (!trim($line)) continue;
        putenv(trim($line));
    }
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();