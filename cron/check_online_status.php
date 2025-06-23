<?php
// Lade PDO und Composer-Autoloader
require_once __DIR__  . '/../class/Model/PdoConnect.php'; 
// Autoloader für Composer-Pakete laden
require __DIR__ . '/../vendor/autoload.php';
// Umgebungsvariablen laden
require_once __DIR__ . '/../config/env.php';

// Fehlerbehandlung: Fehler werden ins Log geschrieben, aber nicht ausgegeben
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php-error.log');

try {
    // Datenbankverbindung herstellen
    $pdo = new App\Model\PdoConnect();

    $timeout = 20; // Sekunden

    // Setze Benutzer offline, wenn sie länger als $timeout Sekunden inaktiv waren
    $sql_offline = "UPDATE user SET user_status = 'offline' WHERE updated_at < (NOW() - INTERVAL $timeout SECOND)";
    $affected = $pdo::$connection->exec($sql_offline);

    // Optional: Logging für Cronjobs (nur zur Überwachung/Debug)
    // error_log("Cron: $affected Nutzer auf offline gesetzt (" . date('c') . ")");

} catch (Exception $e) {
    // Fehler ins Log schreiben
    error_log("Fehler im Cronjob: " . $e->getMessage());
}

?>
