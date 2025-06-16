<?php
require_once __DIR__  . '/../class/Model/PdoConnect.php'; 

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php-error.log');

$pdo = new App\Model\PdoConnect();

$timeout = 20; // Sekunden

// Setze offline, wenn User zu lange inaktiv war
$sql_offline = "UPDATE user SET user_status = 'offline' WHERE updated_at < (NOW() - INTERVAL $timeout SECOND)";
$pdo::$connection->exec($sql_offline);

?>
